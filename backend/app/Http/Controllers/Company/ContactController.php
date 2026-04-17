<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Services\ContactCsvImportService;
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

    public function importCsv(Request $request): JsonResponse
    {
        $companyId = $this->resolveCompanyId($request);
        if ($companyId === null) {
            return response()->json(['message' => 'Empresa não identificada.'], 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'], // 5 MB
        ]);

        $result = $this->csvImport->import($request->file('file'), $companyId);

        return response()->json([
            'ok'       => true,
            'imported' => $result['imported'],
            'skipped'  => $result['skipped'],
            'errors'   => $result['errors'],
        ]);
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
