<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\ImportContactsCsvRequest;
use App\Http\Requests\Company\StoreContactRequest;
use App\Models\Contact;
use App\Services\ContactCsvImportService;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function __construct(private ContactCsvImportService $csvImport) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return response()->json(['message' => 'Empresa não identificada.'], 403);
        }

        $contacts = Contact::where('company_id', $companyId)
            ->orderBy('name')
            ->paginate(50);

        return response()->json($contacts);
    }

    public function importCsv(ImportContactsCsvRequest $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return response()->json(['message' => 'Empresa não identificada.'], 403);
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
            return response()->json(['message' => 'Empresa não identificada.'], 403);
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
            'company_id' => $companyId,
            'name' => trim((string) $validated['name']),
            'phone' => $phone,
            'last_interaction_at' => null,
        ]);

        return response()->json([
            'ok' => true,
            'contact' => $contact,
        ], 201);
    }

    public function destroy(Request $request, int $contactId): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return response()->json(['message' => 'Empresa não identificada.'], 403);
        }

        $contact = Contact::where('company_id', $companyId)->find($contactId);
        if ($contact === null) {
            return response()->json(['message' => 'Contato não encontrado.'], 404);
        }

        $contact->delete();

        return response()->json(['ok' => true]);
    }

    private function resolveCompanyId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user) {
            return null;
        }

        $companyId = $user->isSystemAdmin()
            ? (int) $request->integer('company_id', 0)
            : (int) $user->company_id;

        return $companyId > 0 ? $companyId : null;
    }
}
