<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ImportContactsCsvRequest;
use App\Http\Requests\Company\StoreContactRequest;
use App\Http\Requests\Company\UpdateContactMetaNumberRequest;
use App\Http\Requests\Company\UpdateContactRequest;
use App\Models\Contact;
use App\Services\AuditLogService;
use App\Services\ContactCsvImportService;
use App\Services\Company\CompanyMetaNumberService;
use App\Services\AuditService;
use App\Support\AuditActions;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ContactController extends Controller
{
    public function __construct(
        private readonly ContactCsvImportService $csvImport,
        private readonly AuditLogService $auditLog,
        private readonly CompanyMetaNumberService $companyMetaNumberService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return $this->errorResponse('Empresa não identificada.', 'company_not_found', 403);
        }

        $contacts = Contact::with(['addedBy:id,name', 'defaultAttendant:id,name'])
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->paginate(50);

        return response()->json($contacts);
    }

    public function importCsv(ImportContactsCsvRequest $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return $this->errorResponse('Empresa não identificada.', 'company_not_found', 403);
        }

        $result = $this->csvImport->import($request->file('file'), $companyId);

        return response()->json([
            'ok'       => true,
            'imported' => $result['imported'],
            'skipped'  => $result['skipped'],
            'errors'   => $result['errors'],
        ]);
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return $this->errorResponse('Empresa não identificada.', 'company_not_found', 403);
        }

        $validated = $request->validated();

        $phone = PhoneNumberNormalizer::normalizeBrazil((string) $validated['phone']);
        if ($phone === '') {
            return response()->json([
                'message' => 'Telefone inválido.',
                'errors' => ['phone' => ['Telefone inválido.']],
            ], 422);
        }

        $alreadyExists = Contact::where('company_id', $companyId)
            ->where('phone', $phone)
            ->exists();

        if ($alreadyExists) {
            return response()->json([
                'message' => 'Ja existe um contato com este telefone.',
                'errors' => ['phone' => ['Ja existe um contato com este telefone.']],
            ], 422);
        }

        $defaultAttendantId = isset($validated['default_attendant_user_id'])
            ? (int) $validated['default_attendant_user_id']
            : null;

        $skipBot = (bool) ($validated['skip_bot_to_default_attendant'] ?? false);

        if ($defaultAttendantId === null) {
            $skipBot = false;
        }

        $metaNumberId = null;
        if (array_key_exists('meta_number_id', $validated) && $validated['meta_number_id'] !== null) {
            try {
                $this->companyMetaNumberService->assertBelongsToCompanyAndActive($companyId, (int) $validated['meta_number_id']);
            } catch (RuntimeException $exception) {
                return $this->metaNumberErrorResponse($exception);
            }
            $metaNumberId = (int) $validated['meta_number_id'];
        }

        $contact = Contact::create([
            'company_id'       => $companyId,
            'name'             => trim((string) $validated['name']),
            'phone'            => $phone,
            'last_interaction_at' => null,
            'source'           => 'manual',
            'added_by_user_id' => auth()->id(),
            'default_attendant_user_id' => $defaultAttendantId,
            'skip_bot_to_default_attendant' => $skipBot,
            'meta_number_id' => $metaNumberId,

        ]);

        if ($metaNumberId !== null) {
            AuditService::log(
                AuditActions::CONTACT_META_NUMBER_CHANGED,
                'contact',
                $contact->id,
                ['before' => ['meta_number_id' => null]],
                [
                    'actor_user_id' => (int) (auth()->id() ?? 0),
                    'company_id' => (int) $companyId,
                    'entity_type' => 'contact',
                    'entity_id' => (int) $contact->id,
                    'after' => ['meta_number_id' => $metaNumberId],
                ]
            );
        }

        if ($defaultAttendantId !== null) {
            $this->auditLog->record($request, 'company.contact.default_attendant_updated', $companyId, [
                'contact_id' => (int) $contact->id,
                'contact_phone' => (string) $contact->phone,
                'before_default_attendant_user_id' => null,
                'after_default_attendant_user_id' => (int) $defaultAttendantId,
            ]);
        }

        if ($skipBot) {
            $this->auditLog->record($request, 'company.contact.skip_bot_updated', $companyId, [
                'contact_id' => (int) $contact->id,
                'contact_phone' => (string) $contact->phone,
                'before_skip_bot_to_default_attendant' => false,
                'after_skip_bot_to_default_attendant' => true,
            ]);
        }

        return response()->json([
            'ok' => true,
            'contact' => $contact->fresh(['addedBy:id,name', 'defaultAttendant:id,name']),
        ], 201);
    }

    public function update(UpdateContactRequest $request, int $contactId): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return $this->errorResponse('Empresa não identificada.', 'company_not_found', 403);
        }

        $contact = Contact::where('company_id', $companyId)->find($contactId);
        if ($contact === null) {
            return $this->errorResponse('Contato não encontrado.', 'not_found', 404);
        }

        $validated = $request->validated();

        $phone = PhoneNumberNormalizer::normalizeBrazil((string) $validated['phone']);
        if ($phone === '') {
            return response()->json([
                'message' => 'Telefone inválido.',
                'errors'  => ['phone' => ['Telefone inválido.']],
            ], 422);
        }

        $duplicate = Contact::where('company_id', $companyId)
            ->where('phone', $phone)
            ->where('id', '!=', $contactId)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Já existe um contato com este telefone.',
                'errors'  => ['phone' => ['Já existe um contato com este telefone.']],
            ], 422);
        }

        $defaultAttendantId = isset($validated['default_attendant_user_id'])
            ? (int) $validated['default_attendant_user_id']
            : null;

        $skipBot = (bool) ($validated['skip_bot_to_default_attendant'] ?? false);

        if ($defaultAttendantId === null) {
            $skipBot = false;
        }

        $beforeDefaultAttendantId = $contact->default_attendant_user_id !== null
            ? (int) $contact->default_attendant_user_id
            : null;
        $beforeSkipBot = (bool) ($contact->skip_bot_to_default_attendant ?? false);
        $beforeMetaNumberId = $contact->meta_number_id !== null ? (int) $contact->meta_number_id : null;

        $metaNumberId = null;
        if (array_key_exists('meta_number_id', $validated)) {
            if ($validated['meta_number_id'] !== null) {
                try {
                    $this->companyMetaNumberService->assertBelongsToCompanyAndActive($companyId, (int) $validated['meta_number_id']);
                } catch (RuntimeException $exception) {
                    return $this->metaNumberErrorResponse($exception);
                }
                $metaNumberId = (int) $validated['meta_number_id'];
            }
        } else {
            $metaNumberId = $beforeMetaNumberId;
        }

        $contact->update([
            'name'  => mb_substr(trim((string) $validated['name']), 0, 160),
            'phone' => $phone,
            'default_attendant_user_id' => $defaultAttendantId,
            'skip_bot_to_default_attendant' => $skipBot,
            'meta_number_id' => $metaNumberId,
        ]);

        if ($beforeDefaultAttendantId !== $defaultAttendantId) {
            $this->auditLog->record($request, 'company.contact.default_attendant_updated', $companyId, [
                'contact_id' => (int) $contact->id,
                'contact_phone' => (string) $contact->phone,
                'before_default_attendant_user_id' => $beforeDefaultAttendantId,
                'after_default_attendant_user_id' => $defaultAttendantId,
            ]);
        }

        if ($beforeSkipBot !== $skipBot) {
            $this->auditLog->record($request, 'company.contact.skip_bot_updated', $companyId, [
                'contact_id' => (int) $contact->id,
                'contact_phone' => (string) $contact->phone,
                'before_skip_bot_to_default_attendant' => $beforeSkipBot,
                'after_skip_bot_to_default_attendant' => $skipBot,
            ]);
        }

        $afterMetaNumberId = $contact->meta_number_id !== null ? (int) $contact->meta_number_id : null;
        if ($beforeMetaNumberId !== $afterMetaNumberId) {
            AuditService::log(
                AuditActions::CONTACT_META_NUMBER_CHANGED,
                'contact',
                $contact->id,
                ['before' => ['meta_number_id' => $beforeMetaNumberId]],
                [
                    'actor_user_id' => (int) (auth()->id() ?? 0),
                    'company_id' => (int) $companyId,
                    'entity_type' => 'contact',
                    'entity_id' => (int) $contact->id,
                    'after' => ['meta_number_id' => $afterMetaNumberId],
                ]
            );
        }

        return response()->json(['ok' => true, 'contact' => $contact->fresh(['addedBy:id,name', 'defaultAttendant:id,name'])]);
    }

    public function destroy(Request $request, int $contactId): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return $this->errorResponse('Empresa não identificada.', 'company_not_found', 403);
        }

        $contact = Contact::where('company_id', $companyId)->find($contactId);
        if ($contact === null) {
            return $this->errorResponse('Contato não encontrado.', 'not_found', 404);
        }

        $contact->delete();

        return response()->json(['ok' => true]);
    }

    public function updateMetaNumber(UpdateContactMetaNumberRequest $request, int $contactId): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return $this->errorResponse('Empresa não identificada.', 'company_not_found', 403);
        }

        $contact = Contact::query()
            ->where('company_id', $companyId)
            ->find($contactId);

        if (! $contact) {
            return $this->errorResponse('Contato não encontrado.', 'not_found', 404);
        }

        $validated = $request->validated();
        $beforeMetaNumberId = $contact->meta_number_id !== null ? (int) $contact->meta_number_id : null;
        $afterMetaNumberId = null;

        if (array_key_exists('meta_number_id', $validated) && $validated['meta_number_id'] !== null) {
            try {
                $number = $this->companyMetaNumberService->assertBelongsToCompanyAndActive($companyId, (int) $validated['meta_number_id']);
                $afterMetaNumberId = (int) $number->id;
            } catch (RuntimeException $exception) {
                return $this->metaNumberErrorResponse($exception);
            }
        }

        $contact->meta_number_id = $afterMetaNumberId;
        $contact->save();

        if ($beforeMetaNumberId !== $afterMetaNumberId) {
            AuditService::log(
                AuditActions::CONTACT_META_NUMBER_CHANGED,
                'contact',
                $contact->id,
                ['before' => ['meta_number_id' => $beforeMetaNumberId]],
                [
                    'actor_user_id' => (int) (auth()->id() ?? 0),
                    'company_id' => (int) $companyId,
                    'entity_type' => 'contact',
                    'entity_id' => (int) $contact->id,
                    'after' => ['meta_number_id' => $afterMetaNumberId],
                    'reason' => 'contact_meta_number_endpoint',
                ]
            );
        }

        return response()->json([
            'ok' => true,
            'contact' => $contact->fresh(['addedBy:id,name', 'defaultAttendant:id,name', 'metaNumber:id,company_id,phone_number,display_name,is_active,is_primary']),
        ]);
    }

    public function listMetaNumbers(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return $this->errorResponse('Empresa nÃ£o identificada.', 'company_not_found', 403);
        }

        $items = $this->companyMetaNumberService->listActive((int) $companyId)
            ->map(fn ($item) => $item->only(['id', 'company_id', 'phone_number', 'display_name', 'is_active', 'is_primary']))
            ->values();

        return response()->json(['items' => $items]);
    }

    private function metaNumberErrorResponse(RuntimeException $exception): JsonResponse
    {
        return match ($exception->getMessage()) {
            'META_NUMBER_NOT_FOUND' => $this->errorResponse('META_NUMBER_NOT_FOUND', 'META_NUMBER_NOT_FOUND', 404),
            'META_NUMBER_COMPANY_MISMATCH' => $this->errorResponse('META_NUMBER_COMPANY_MISMATCH', 'META_NUMBER_COMPANY_MISMATCH', 422),
            'META_NUMBER_INACTIVE' => $this->errorResponse('META_NUMBER_INACTIVE', 'META_NUMBER_INACTIVE', 422),
            default => $this->errorResponse($exception->getMessage(), $exception->getMessage(), 422),
        };
    }
}
