<?php

declare(strict_types=1);

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\IxcUrlGuard;
use App\Services\AuditLogService;
use App\Services\IxcApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class IxcClientController extends Controller
{
    public function __construct(
        private readonly IxcApiService $ixcApi,
        private readonly AuditLogService $auditLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $query = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(200, max(10, (int) $request->integer('per_page', 30)));

        try {
            $list = $this->searchClients($company, $query, $page, $perPage);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'items' => array_map(fn(array $row) => $this->mapClientItem($row), $list['items']),
            'pagination' => [
                'page' => $list['page'],
                'per_page' => $list['per_page'],
                'total' => $list['total'],
                'has_next' => ($list['page'] * $list['per_page']) < $list['total'],
            ],
            'filters' => [
                'q' => $query,
            ],
        ]);
    }

    /**
     * @return array{items: array<int, array<string,mixed>>, total: int, page: int, per_page: int}
     */
    private function searchClients(Company $company, string $query, int $page, int $perPage): array
    {
        $baseParams = [
            'page' => $page,
            'rp' => $perPage,
            'sortorder' => 'asc',
        ];
        $attemptResults = [];
        $queryDigits = preg_replace('/\D+/', '', $query) ?? '';
        $nameLike = '%' . $query . '%';
        $digitLike = $queryDigits !== '' ? '%' . $queryDigits . '%' : '';
        $attempts = $query === ''
            ? [
                [
                    'qtype' => 'cliente.id',
                    'query' => '0',
                    'oper' => '>=',
                    'sortname' => 'cliente.id',
                ],
                [
                    'qtype' => 'cliente.id',
                    'query' => '0',
                    'oper' => '>',
                    'sortname' => 'cliente.id',
                ],
                [
                    'qtype' => 'cliente.id',
                    'query' => '0',
                    'oper' => '!=',
                    'sortname' => 'cliente.id',
                ],
                [
                    'qtype' => 'cliente.nome',
                    'query' => '%',
                    'oper' => 'like',
                    'sortname' => 'cliente.nome',
                ],
                [
                    'qtype' => 'cliente.razao',
                    'query' => '%',
                    'oper' => 'like',
                    'sortname' => 'cliente.razao',
                ],
            ]
            : [
                [
                    'qtype' => 'cliente.razao',
                    'query' => $nameLike,
                    'oper' => 'like',
                    'sortname' => 'cliente.razao',
                ],
                [
                    'qtype' => 'cliente.nome',
                    'query' => $nameLike,
                    'oper' => 'like',
                    'sortname' => 'cliente.nome',
                ],
                [
                    'qtype' => 'cliente.fantasia',
                    'query' => $nameLike,
                    'oper' => 'like',
                    'sortname' => 'cliente.fantasia',
                ],
                [
                    'qtype' => 'cliente.cnpj_cpf',
                    'query' => $queryDigits !== '' ? $queryDigits : $query,
                    'oper' => '=',
                    'sortname' => 'cliente.id',
                    'only_when_digits' => true,
                ],
                [
                    'qtype' => 'cliente.cnpj_cpf',
                    'query' => $digitLike,
                    'oper' => 'like',
                    'sortname' => 'cliente.id',
                    'only_when_digits' => true,
                ],
                [
                    'qtype' => 'cliente.telefone_celular',
                    'query' => $digitLike,
                    'oper' => 'like',
                    'sortname' => 'cliente.id',
                    'only_when_digits' => true,
                ],
                [
                    'qtype' => 'cliente.id',
                    'query' => ctype_digit($query) ? $query : '-1',
                    'oper' => '=',
                    'sortname' => 'cliente.id',
                    'only_when_numeric_query' => true,
                ],
            ];

        $lastList = [
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
        $lastError = null;

        foreach ($attempts as $attempt) {
            if (($attempt['only_when_digits'] ?? false) && $queryDigits === '') {
                continue;
            }
            if (($attempt['only_when_numeric_query'] ?? false) && ! ctype_digit($query)) {
                continue;
            }

            $params = array_merge($baseParams, [
                'qtype' => (string) $attempt['qtype'],
                'query' => (string) $attempt['query'],
                'oper' => (string) $attempt['oper'],
                'sortname' => (string) $attempt['sortname'],
            ]);

            try {
                $payload = $this->ixcApi->request($company, 'cliente', $params);
                $list = $this->ixcApi->normalizeList($payload, $page, $perPage);
                $lastList = $list;
                $attemptResults[] = [
                    'qtype' => (string) $params['qtype'],
                    'oper' => (string) $params['oper'],
                    'item_count' => count($list['items'] ?? []),
                    'total' => (int) ($list['total'] ?? 0),
                ];
                if (($list['total'] ?? 0) > 0 || count($list['items'] ?? []) > 0) {
                    return $list;
                }
            } catch (RuntimeException $exception) {
                $lastError = $exception;
                $attemptResults[] = [
                    'qtype' => (string) $params['qtype'],
                    'oper' => (string) $params['oper'],
                    'error' => $exception->getMessage(),
                ];
                continue;
            }
        }

        Log::info('ixc.client.search.empty', [
            'company_id' => (int) $company->id,
            'query' => $query === '' ? '[empty]' : $query,
            'attempts' => $attemptResults,
        ]);

        if ($lastError instanceof RuntimeException && count($lastList['items']) === 0) {
            throw $lastError;
        }

        return $lastList;
    }

    public function show(Request $request, string $clientId): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $params = [
            'qtype' => 'cliente.id',
            'query' => $clientId,
            'oper' => '=',
            'page' => 1,
            'rp' => 1,
            'sortname' => 'cliente.id',
            'sortorder' => 'asc',
        ];

        try {
            $payload = $this->ixcApi->request($company, 'cliente', $params);
            $list = $this->ixcApi->normalizeList($payload, 1, 1);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        $client = $list['items'][0] ?? null;
        if (! is_array($client)) {
            return response()->json([
                'ok' => false,
                'message' => 'Cliente nao encontrado na IXC.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'client' => $this->mapClientDetail($client),
        ]);
    }

    public function invoices(Request $request, string $clientId): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $status = strtolower(trim((string) $request->query('status', 'all')));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(200, max(10, (int) $request->integer('per_page', 30)));

        $gridFilters = [
            ['TB' => 'fn_areceber.liberado', 'OP' => '=', 'P' => 'S'],
        ];
        if ($status === 'open') {
            $gridFilters[] = ['TB' => 'fn_areceber.status', 'OP' => '!=', 'P' => 'C'];
            $gridFilters[] = ['TB' => 'fn_areceber.status', 'OP' => '!=', 'P' => 'R'];
        } elseif ($status === 'closed' || $status === 'paid') {
            $gridFilters[] = ['TB' => 'fn_areceber.status', 'OP' => '=', 'P' => 'R'];
        }
        if ($dateFrom !== '') {
            $gridFilters[] = ['TB' => 'fn_areceber.data_vencimento', 'OP' => '>=', 'P' => $dateFrom];
        }
        if ($dateTo !== '') {
            $gridFilters[] = ['TB' => 'fn_areceber.data_vencimento', 'OP' => '<=', 'P' => $dateTo];
        }

        $params = [
            'qtype' => 'fn_areceber.id_cliente',
            'query' => $clientId,
            'oper' => '=',
            'page' => $page,
            'rp' => $perPage,
            'sortname' => 'fn_areceber.data_vencimento',
            'sortorder' => 'asc',
            'grid_param' => json_encode($gridFilters),
        ];

        try {
            $payload = $this->ixcApi->request($company, 'fn_areceber', $params);
            $list = $this->ixcApi->normalizeList($payload, $page, $perPage);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'client_id' => (int) $clientId,
            'items' => array_map(fn(array $row) => $this->mapInvoiceItem($row), $list['items']),
            'pagination' => [
                'page' => $list['page'],
                'per_page' => $list['per_page'],
                'total' => $list['total'],
                'has_next' => ($list['page'] * $list['per_page']) < $list['total'],
            ],
            'filters' => [
                'status' => in_array($status, ['open', 'closed', 'paid'], true) ? $status : 'all',
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    public function invoiceDetail(Request $request, string $clientId, string $invoiceId): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $invoice = $this->loadInvoiceRow($company, $clientId, $invoiceId);
        if (! is_array($invoice)) {
            return response()->json(['ok' => false, 'message' => 'Boleto nao encontrado.'], 404);
        }

        $this->auditLog->record(
            $request,
            'company.ixc.invoice.detail',
            (int) $company->id,
            [
                'ixc_client_id' => (int) $clientId,
                'ixc_invoice_id' => (int) $invoiceId,
            ],
        );

        return response()->json([
            'ok' => true,
            'item' => $this->mapInvoiceItem($invoice),
        ]);
    }

    public function downloadInvoice(Request $request, string $clientId, string $invoiceId): Response
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $invoice = $this->loadInvoiceRow($company, $clientId, $invoiceId);
        if (! is_array($invoice)) {
            return response()->json(['ok' => false, 'message' => 'Boleto nao encontrado.'], 404);
        }

        $binaryPayload = $this->resolveInvoiceBinary($company, $invoice, $clientId, $invoiceId);
        if (! is_array($binaryPayload) || ($binaryPayload['binary'] ?? '') === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Nao foi possivel obter o arquivo do boleto na IXC.',
            ], 422);
        }

        $filename = $this->resolveInvoiceFilename($invoiceId, (string) ($invoice['data_vencimento'] ?? ''));
        $contentType = (string) ($binaryPayload['content_type'] ?? 'application/pdf');

        $this->auditLog->record(
            $request,
            'company.ixc.invoice.download',
            (int) $company->id,
            [
                'ixc_client_id' => (int) $clientId,
                'ixc_invoice_id' => (int) $invoiceId,
                'content_type' => $contentType,
            ],
        );

        return response((string) $binaryPayload['binary'], 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function sendInvoiceEmail(Request $request, string $clientId, string $invoiceId): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:190'],
        ]);

        $invoice = $this->loadInvoiceRow($company, $clientId, $invoiceId);
        if (! is_array($invoice)) {
            return response()->json(['ok' => false, 'message' => 'Boleto nao encontrado.'], 404);
        }

        try {
            $sendResult = $this->sendInvoiceThroughIxc($company, $clientId, $invoiceId, 'email', [
                'email' => (string) $validated['email'],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->auditLog->record(
            $request,
            'company.ixc.invoice.send_email',
            (int) $company->id,
            [
                'ixc_client_id' => (int) $clientId,
                'ixc_invoice_id' => (int) $invoiceId,
                'destination_masked' => $this->maskEmail((string) $validated['email']),
                'provider_status' => (string) ($sendResult['provider_status'] ?? 'ok'),
            ],
        );

        return response()->json([
            'ok' => true,
            'message' => 'Boleto enviado por e-mail com sucesso.',
            'provider_status' => $sendResult['provider_status'] ?? 'ok',
        ]);
    }

    public function sendInvoiceSms(Request $request, string $clientId, string $invoiceId): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:25'],
        ]);

        $invoice = $this->loadInvoiceRow($company, $clientId, $invoiceId);
        if (! is_array($invoice)) {
            return response()->json(['ok' => false, 'message' => 'Boleto nao encontrado.'], 404);
        }

        try {
            $sendResult = $this->sendInvoiceThroughIxc($company, $clientId, $invoiceId, 'sms', [
                'phone' => (string) $validated['phone'],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }

        $this->auditLog->record(
            $request,
            'company.ixc.invoice.send_sms',
            (int) $company->id,
            [
                'ixc_client_id' => (int) $clientId,
                'ixc_invoice_id' => (int) $invoiceId,
                'destination_masked' => $this->maskPhone((string) $validated['phone']),
                'provider_status' => (string) ($sendResult['provider_status'] ?? 'ok'),
            ],
        );

        return response()->json([
            'ok' => true,
            'message' => 'Boleto enviado por SMS com sucesso.',
            'provider_status' => $sendResult['provider_status'] ?? 'ok',
        ]);
    }

    private function resolveCompany(Request $request): Company|JsonResponse
    {
        $user = $request->user();
        $companyId = (int) ($user?->company_id ?? 0);
        if ($companyId <= 0) {
            return response()->json(['ok' => false, 'message' => 'Empresa nao encontrada.'], 404);
        }

        $company = Company::find($companyId);
        if (! $company) {
            return response()->json(['ok' => false, 'message' => 'Empresa nao encontrada.'], 404);
        }

        return $company;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapClientItem(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'razao' => (string) ($row['razao'] ?? $row['nome'] ?? ''),
            'fantasia' => (string) ($row['fantasia'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'telefone_celular' => (string) ($row['telefone_celular'] ?? $row['fone'] ?? ''),
            'cpf_cnpj' => (string) ($row['cnpj_cpf'] ?? ''),
            'ativo' => (string) ($row['ativo'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapClientDetail(array $row): array
    {
        return $this->mapClientItem($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapInvoiceItem(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'id_cliente' => (int) ($row['id_cliente'] ?? 0),
            'status' => (string) ($row['status'] ?? ''),
            'valor' => (string) ($row['valor'] ?? $row['valor_parcela'] ?? ''),
            'data_vencimento' => (string) ($row['data_vencimento'] ?? ''),
            'linha_digitavel' => (string) ($row['linha_digitavel'] ?? ''),
            'documento' => (string) ($row['documento'] ?? ''),
        ];
    }

    private function loadInvoiceRow(Company $company, string $clientId, string $invoiceId): ?array
    {
        $params = [
            'qtype' => 'fn_areceber.id',
            'query' => $invoiceId,
            'oper' => '=',
            'page' => 1,
            'rp' => 1,
            'sortname' => 'fn_areceber.id',
            'sortorder' => 'desc',
            'grid_param' => json_encode([
                ['TB' => 'fn_areceber.id_cliente', 'OP' => '=', 'P' => (string) $clientId],
            ]),
        ];

        try {
            $payload = $this->ixcApi->request($company, 'fn_areceber', $params);
            $list = $this->ixcApi->normalizeList($payload, 1, 1);
        } catch (RuntimeException) {
            return null;
        }

        $row = $list['items'][0] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array{binary:string, content_type:string}|null
     */
    private function resolveInvoiceBinary(Company $company, array $invoice, string $clientId, string $invoiceId): ?array
    {
        $baseHost = strtolower((string) (parse_url((string) ($company->ixc_base_url ?? ''), PHP_URL_HOST) ?? ''));
        $allowPrivateHosts = (bool) config('ixc.allow_private_hosts', false);
        $urlKeys = ['url', 'link', 'link_boleto', 'boleto_link', 'pdf_url', 'arquivo_url'];
        foreach ($urlKeys as $key) {
            $value = trim((string) ($invoice[$key] ?? ''));
            if ($value === '' || ! IxcUrlGuard::isSafeInvoiceUrl($value, $baseHost, $allowPrivateHosts)) {
                continue;
            }

            try {
                $response = Http::timeout(max(5, min(60, (int) ($company->ixc_timeout_seconds ?? 15))))
                    ->withOptions(['verify' => ! (bool) $company->ixc_self_signed])
                    ->get($value);
            } catch (\Throwable) {
                continue;
            }

            if ($response->successful() && $response->body() !== '') {
                return [
                    'binary' => (string) $response->body(),
                    'content_type' => (string) $response->header('Content-Type', 'application/pdf'),
                ];
            }
        }

        $base64Keys = ['pdf_base64', 'arquivo_base64', 'boleto_base64', 'pdf'];
        foreach ($base64Keys as $key) {
            $value = trim((string) ($invoice[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            $decoded = base64_decode($value, true);
            if ($decoded !== false && $decoded !== '') {
                return [
                    'binary' => $decoded,
                    'content_type' => 'application/pdf',
                ];
            }
        }

        try {
            $binaryAttempt = $this->ixcApi->requestBinary($company, 'get_boleto', [
                'id' => $invoiceId,
                'id_cliente' => $clientId,
            ]);
            if ($binaryAttempt['body'] !== '') {
                return [
                    'binary' => (string) $binaryAttempt['body'],
                    'content_type' => (string) ($binaryAttempt['content_type'] ?? 'application/pdf'),
                ];
            }
        } catch (RuntimeException) {
            return null;
        }

        return null;
    }

    private function resolveInvoiceFilename(string $invoiceId, string $dueDate): string
    {
        $normalizedDate = preg_replace('/[^0-9]/', '', $dueDate) ?: date('Ymd');
        return "boleto_{$invoiceId}_{$normalizedDate}.pdf";
    }

    /**
     * @param  array<string, string>  $destination
     * @return array<string, mixed>
     */
    private function sendInvoiceThroughIxc(Company $company, string $clientId, string $invoiceId, string $channel, array $destination): array
    {
        $attempts = $channel === 'email'
            ? [
                ['resource' => 'get_boleto', 'method' => 'post', 'payload' => ['id' => $invoiceId, 'id_cliente' => $clientId, 'email' => $destination['email'] ?? '']],
                ['resource' => 'fn_areceber', 'method' => 'post', 'payload' => ['id' => $invoiceId, 'id_cliente' => $clientId, 'enviar_email' => 'S', 'email' => $destination['email'] ?? '']],
            ]
            : [
                ['resource' => 'get_boleto', 'method' => 'post', 'payload' => ['id' => $invoiceId, 'id_cliente' => $clientId, 'sms' => $destination['phone'] ?? '']],
                ['resource' => 'fn_areceber', 'method' => 'post', 'payload' => ['id' => $invoiceId, 'id_cliente' => $clientId, 'enviar_sms' => 'S', 'celular' => $destination['phone'] ?? '']],
            ];

        $lastError = null;
        foreach ($attempts as $attempt) {
            try {
                $response = $this->ixcApi->request(
                    $company,
                    (string) $attempt['resource'],
                    (array) $attempt['payload'],
                    (string) $attempt['method'],
                );
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();
                continue;
            }

            $providerStatus = $this->readProviderStatus($response);
            if ($providerStatus['ok']) {
                return [
                    'provider_status' => $providerStatus['status'] ?? 'ok',
                    'raw' => $response,
                ];
            }
            $lastError = $providerStatus['error'] ?? 'Falha no provedor IXC.';
        }

        throw new RuntimeException($lastError ?: 'Nao foi possivel enviar boleto pela IXC.');
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{ok:bool,status?:string,error?:string}
     */
    private function readProviderStatus(array $response): array
    {
        $okCandidates = [
            Arr::get($response, 'ok'),
            Arr::get($response, 'success'),
            Arr::get($response, 'sucesso'),
        ];
        foreach ($okCandidates as $candidate) {
            if ($candidate === true || $candidate === 1 || $candidate === '1' || $candidate === 'S' || $candidate === 's') {
                return ['ok' => true, 'status' => 'ok'];
            }
        }

        $statusText = strtolower(trim((string) (Arr::get($response, 'status') ?? Arr::get($response, 'mensagem') ?? Arr::get($response, 'message') ?? '')));
        if ($statusText !== '' && (str_contains($statusText, 'sucesso') || str_contains($statusText, 'enviado') || str_contains($statusText, 'ok'))) {
            return ['ok' => true, 'status' => $statusText];
        }

        return [
            'ok' => false,
            'error' => trim((string) (Arr::get($response, 'error') ?? Arr::get($response, 'mensagem') ?? Arr::get($response, 'message') ?? 'Falha no envio.')),
        ];
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        if (count($parts) !== 2) {
            return '***';
        }
        $name = $parts[0];
        $domain = $parts[1];
        $prefix = mb_substr($name, 0, 2);
        return $prefix . '***@' . $domain;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '***';
        }
        $suffix = mb_substr($digits, -4);
        return '***' . $suffix;
    }
}
