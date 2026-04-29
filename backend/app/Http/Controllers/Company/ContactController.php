<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ImportContactsCsvRequest;
use App\Http\Requests\Company\StoreContactRequest;
use App\Http\Requests\Company\UpdateContactRequest;
use App\Models\Contact;
use App\Services\ContactCsvImportService;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(private readonly ContactCsvImportService $csvImport) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return $this->errorResponse('Empresa não identificada.', 'company_not_found', 403);
        }

        $contacts = Contact::with('addedBy:id,name')
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

        $contact = Contact::create([
            'company_id'       => $companyId,
            'name'             => trim((string) $validated['name']),
            'phone'            => $phone,
            'last_interaction_at' => null,
            'source'           => 'manual',
            'added_by_user_id' => auth()->id(),
        ]);

        return response()->json([
            'ok' => true,
            'contact' => $contact,
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

        $contact->update([
            'name'  => mb_substr(trim((string) $validated['name']), 0, 160),
            'phone' => $phone,
        ]);

        return response()->json(['ok' => true, 'contact' => $contact->fresh('addedBy')]);
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
