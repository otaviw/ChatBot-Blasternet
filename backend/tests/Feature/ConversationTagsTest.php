<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyBotSetting;
use App\Models\Conversation;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTagsTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompanyAndUser(): array
    {
        $company = Company::create(['name' => 'Tags Test Co']);
        CompanyBotSetting::create(['company_id' => $company->id]);
        $user = User::create([
            'name'       => 'Atendente',
            'email'      => 'atendente-tags@test.local',
            'password'   => 'secret',
            'role'       => 'company',
            'company_id' => $company->id,
            'is_active'  => true,
        ]);

        return [$company, $user];
    }

    private function makeConversation(Company $company): Conversation
    {
        return Conversation::create([
            'company_id'     => $company->id,
            'customer_phone' => '5511900000001',
            'status'         => 'open',
            'handling_mode'  => 'bot',
        ]);
    }

    // ─── Tag CRUD ────────────────────────────────────────────────────────────

    public function test_company_user_can_create_tag(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();

        $response = $this->actingAs($user)->postJson('/api/minha-conta/tags', [
            'name'  => 'Urgente',
            'color' => '#ef4444',
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['name' => 'urgente', 'color' => '#ef4444']);
        $this->assertDatabaseHas('tags', [
            'company_id' => $company->id,
            'name'       => 'urgente',
            'color'      => '#ef4444',
        ]);
    }

    public function test_tag_name_is_stored_lowercased(): void
    {
        [, $user] = $this->makeCompanyAndUser();

        $this->actingAs($user)->postJson('/api/minha-conta/tags', [
            'name'  => '  VIP  ',
            'color' => '#22c55e',
        ])->assertCreated()->assertJsonFragment(['name' => 'vip']);
    }

    public function test_duplicate_tag_name_within_company_is_rejected(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        Tag::create(['company_id' => $company->id, 'name' => 'urgente', 'color' => '#ef4444']);

        $this->actingAs($user)->postJson('/api/minha-conta/tags', [
            'name'  => 'Urgente',
            'color' => '#3b82f6',
        ])->assertUnprocessable();
    }

    public function test_tag_color_must_be_valid_hex(): void
    {
        [, $user] = $this->makeCompanyAndUser();

        $this->actingAs($user)->postJson('/api/minha-conta/tags', [
            'name'  => 'bad-color',
            'color' => 'red',
        ])->assertUnprocessable();
    }

    public function test_company_user_can_update_tag(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $tag = Tag::create(['company_id' => $company->id, 'name' => 'old', 'color' => '#6b7280']);

        $this->actingAs($user)->putJson("/api/minha-conta/tags/{$tag->id}", [
            'name'  => 'novo',
            'color' => '#a855f7',
        ])->assertOk()->assertJsonFragment(['name' => 'novo', 'color' => '#a855f7']);
    }

    public function test_company_user_can_delete_tag(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $tag = Tag::create(['company_id' => $company->id, 'name' => 'deletar', 'color' => '#6b7280']);

        $this->actingAs($user)->deleteJson("/api/minha-conta/tags/{$tag->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_user_cannot_manage_tags_of_another_company(): void
    {
        [, $user] = $this->makeCompanyAndUser();

        $otherCompany = Company::create(['name' => 'Other Co']);
        CompanyBotSetting::create(['company_id' => $otherCompany->id]);
        $foreignTag = Tag::create([
            'company_id' => $otherCompany->id,
            'name'       => 'foreign',
            'color'      => '#6b7280',
        ]);

        $this->actingAs($user)->putJson("/api/minha-conta/tags/{$foreignTag->id}", [
            'name'  => 'hacked',
            'color' => '#000000',
        ])->assertForbidden();

        $this->actingAs($user)->deleteJson("/api/minha-conta/tags/{$foreignTag->id}")
            ->assertForbidden();
    }

    // ─── Attach / Detach ────────────────────────────────────────────────────

    public function test_company_user_can_attach_tag_to_conversation(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $tag          = Tag::create(['company_id' => $company->id, 'name' => 'prioridade', 'color' => '#f97316']);
        $conversation = $this->makeConversation($company);

        $response = $this->actingAs($user)->postJson(
            "/api/minha-conta/conversas/{$conversation->id}/tags",
            ['tag_id' => $tag->id]
        );

        $response->assertOk();
        $this->assertDatabaseHas('conversation_tag', [
            'conversation_id' => $conversation->id,
            'tag_id'          => $tag->id,
        ]);

        // Response should include updated tags list
        $tags = $response->json('tags');
        $this->assertIsArray($tags);
        $tagIds = array_column($tags, 'id');
        $this->assertContains($tag->id, $tagIds);
    }

    public function test_attach_is_idempotent(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $tag          = Tag::create(['company_id' => $company->id, 'name' => 'idem', 'color' => '#6b7280']);
        $conversation = $this->makeConversation($company);
        $conversation->tags()->attach($tag->id);

        // Second attach should not duplicate and should succeed
        $this->actingAs($user)->postJson(
            "/api/minha-conta/conversas/{$conversation->id}/tags",
            ['tag_id' => $tag->id]
        )->assertOk();

        $this->assertCount(1, $conversation->fresh()->tags);
    }

    public function test_company_user_can_detach_tag_from_conversation(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $tag          = Tag::create(['company_id' => $company->id, 'name' => 'remover', 'color' => '#6b7280']);
        $conversation = $this->makeConversation($company);
        $conversation->tags()->attach($tag->id);

        $this->actingAs($user)->deleteJson(
            "/api/minha-conta/conversas/{$conversation->id}/tags/{$tag->id}"
        )->assertOk();

        $this->assertDatabaseMissing('conversation_tag', [
            'conversation_id' => $conversation->id,
            'tag_id'          => $tag->id,
        ]);
    }

    public function test_cannot_attach_tag_from_another_company(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $conversation = $this->makeConversation($company);

        $otherCompany = Company::create(['name' => 'Other Co 2']);
        CompanyBotSetting::create(['company_id' => $otherCompany->id]);
        $foreignTag = Tag::create([
            'company_id' => $otherCompany->id,
            'name'       => 'foreign2',
            'color'      => '#6b7280',
        ]);

        $this->actingAs($user)->postJson(
            "/api/minha-conta/conversas/{$conversation->id}/tags",
            ['tag_id' => $foreignTag->id]
        )->assertForbidden();
    }

    // ─── Filter by tag_id ───────────────────────────────────────────────────

    public function test_conversations_can_be_filtered_by_tag_id(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $tag = Tag::create(['company_id' => $company->id, 'name' => 'filtrar', 'color' => '#3b82f6']);

        $tagged   = $this->makeConversation($company);
        $untagged = $this->makeConversation($company);
        $tagged->tags()->attach($tag->id);

        $response = $this->actingAs($user)->getJson(
            "/api/minha-conta/conversas?tag_id={$tag->id}"
        );

        $response->assertOk();
        $ids = array_column($response->json('conversations'), 'id');
        $this->assertContains($tagged->id, $ids);
        $this->assertNotContains($untagged->id, $ids);
    }

    public function test_conversations_list_includes_tags_array(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $tag          = Tag::create(['company_id' => $company->id, 'name' => 'show-tag', 'color' => '#22c55e']);
        $conversation = $this->makeConversation($company);
        $conversation->tags()->attach($tag->id);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/conversas');

        $response->assertOk();
        $conversations = $response->json('conversations');
        $found         = collect($conversations)->firstWhere('id', $conversation->id);
        $this->assertNotNull($found);
        $tagIds = array_column($found['tags'] ?? [], 'id');
        $this->assertContains($tag->id, $tagIds);
    }

    public function test_company_tags_are_returned_in_conversations_response(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        Tag::create(['company_id' => $company->id, 'name' => 'ct1', 'color' => '#ef4444']);
        Tag::create(['company_id' => $company->id, 'name' => 'ct2', 'color' => '#3b82f6']);

        $response = $this->actingAs($user)->getJson('/api/minha-conta/conversas');

        $response->assertOk();
        $companyTags = $response->json('company_tags');
        $this->assertIsArray($companyTags);
        $this->assertCount(2, $companyTags);
    }

    public function test_deleting_tag_removes_pivot_entries(): void
    {
        [$company, $user] = $this->makeCompanyAndUser();
        $tag          = Tag::create(['company_id' => $company->id, 'name' => 'cascade', 'color' => '#6b7280']);
        $conversation = $this->makeConversation($company);
        $conversation->tags()->attach($tag->id);

        $this->actingAs($user)->deleteJson("/api/minha-conta/tags/{$tag->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('conversation_tag', [
            'conversation_id' => $conversation->id,
            'tag_id'          => $tag->id,
        ]);
    }
}
