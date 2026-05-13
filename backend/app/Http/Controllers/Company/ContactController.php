<?php

declare(strict_types=1);


namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ImportContactsCsvRequest;
use App\Http\Requests\Company\StoreContactRequest;
use App\Http\Requests\Company\UpdateContactRequest;
use App\Models\Contact;
use App\Services\AuditLogService;
use App\Services\ContactCsvImportService;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(
        private readonly ContactCsvImportService $csvImport,
        private readonly AuditLogService $auditLog
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

        $contact = Contact::create([
            'company_id'       => $companyId,
            'name'             => trim((string) $validated['name']),
            'phone'            => $phone,
            'last_interaction_at' => null,
            'source'           => 'manual',
            'added_by_user_id' => auth()->id(),
            'default_attendant_user_id' => $defaultAttendantId,
            'skip_bot_to_default_attendant' => $skipBot,

        ]);

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

        $contact->update([
            'name'  => mb_substr(trim((string) $validated['name']), 0, 160),
            'phone' => $phone,
            'default_attendant_user_id' => $defaultAttendantId,
            'skip_bot_to_default_attendant' => $skipBot,
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
}
