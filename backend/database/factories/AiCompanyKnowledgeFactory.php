<?php

namespace Database\Factories;

use App\Models\AiCompanyKnowledge;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCompanyKnowledge>
 */
class AiCompanyKnowledgeFactory extends Factory
{
    protected $model = AiCompanyKnowledge::class;

    public function definition(): array
    {
        return [
            'company_id' => fn () => Company::create(['name' => fake()->company()]),
            'title' => fake()->sentence(4),
            'content' => fake()->paragraph(),
            'is_active' => true,
        ];
    }
}
