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
                'message' => $this->friendlyIxcErrorMessage(
                    $exception->getMessage(),
                    'Nao foi possivel consultar clientes na IXC agora.'
                ),
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
        $query = trim($query);
        $baseParams = [
            'page' => $page,
            'rp' => $perPage,
            'sortorder' => 'asc',
        ];
        $attemptResults = [];
        $attempts = $this->buildClientSearchAttempts($query);

        $lastList = [
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
        ];
        $lastError = null;
        $hadSuccessfulAttempt = false;

        foreach ($attempts as $attempt) {
            $params = array_merge($baseParams, [
                'qtype' => (string) $attempt['qtype'],
                'query' => (string) $attempt['query'],
                'oper' => (string) $attempt['oper'],
                'sortname' => (string) $attempt['sortname'],
            ]);

            try {
                $payload = $this->ixcApi->request($company, 'cliente', $params);
                $list = $this->ixcApi->normalizeList($payload, $page, $perPage);
                $hadSuccessfulAttempt = true;
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

                if ($this->isUnavailableClientResourceError($exception->getMessage())) {
                    $fallback = $this->searchClientsWithAlternativeResources($company, $query, $page, $perPage);
                    if ($fallback !== null) {
                        return $fallback;
                    }

                    throw new RuntimeException(
                        'A listagem de clientes nao esta disponivel na IXC para este usuario/token.'
                    );
                }

                continue;
            }
        }

        Log::info('ixc.client.search.empty', [
            'company_id' => (int) $company->id,
            'query' => $query === '' ? '[empty]' : $query,
            'attempts' => $attemptResults,
        ]);

        if ($hadSuccessfulAttempt) {
            return $lastList;
        }

        if ($lastError instanceof RuntimeException && count($lastList['items']) === 0) {
            throw new RuntimeException(
                $this->friendlyIxcErrorMessage(
                    $lastError->getMessage(),
                    'Nao foi possivel consultar clientes na IXC agora.'
                )
            );
        }

        return $lastList;
    }

    /**
     * @return array<int, array{qtype:string,query:string,oper:string,sortname:string}>
     */
    private function buildClientSearchAttempts(string $query): array
    {
        $query = trim($query);
        $queryDigits = preg_replace('/\D+/', '', $query) ?? '';
        $isDocumentQuery = $this->isCompleteCpfOrCnpj($queryDigits);
        $attempts = [];

        $push = static function (string $qtype, string $value, string $oper, string $sortname) use (&$attempts): void {
            $attempts[] = [
                'qtype' => $qtype,
                'query' => $value,
                'oper' => $oper,
                'sortname' => $sortname,
            ];
        };

        if ($query === '') {
            $push('cliente.id', '0', '>=', 'cliente.id');
            $push('id', '0', '>=', 'id');
        } else {
            if (ctype_digit($query)) {
                $push('cliente.id', $query, '=', 'cliente.id');
                $push('id', $query, '=', 'id');
            }

            if ($queryDigits !== '') {
                foreach ($this->buildDocumentVariants($query, $queryDigits) as $variant) {
                    $push('cliente.cnpj_cpf', $variant, '=', 'cliente.id');
                    $push('cnpj_cpf', $variant, '=', 'id');
                }

                if (! $isDocumentQuery && strlen($queryDigits) >= 4) {
                    $push('cliente.cnpj_cpf', $queryDigits, 'L', 'cliente.id');
                    $push('cnpj_cpf', $queryDigits, 'L', 'id');
                }

                $push('cliente.telefone_celular', $queryDigits, 'L', 'cliente.id');
                $push('telefone_celular', $queryDigits, 'L', 'id');
            }

            if (! $isDocumentQuery) {
                $push('cliente.razao', $query, 'L', 'cliente.razao');
                $push('cliente.nome', $query, 'L', 'cliente.nome');
                $push('cliente.fantasia', $query, 'L', 'cliente.fantasia');
                $push('razao', $query, 'L', 'razao');
                $push('nome', $query, 'L', 'nome');
                $push('fantasia', $query, 'L', 'fantasia');
            }
        }

        $unique = [];
        foreach ($attempts as $attempt) {
            $key = mb_strtolower(implode('|', [
                (string) $attempt['qtype'],
                (string) $attempt['oper'],
                (string) $attempt['query'],
                (string) $attempt['sortname'],
            ]));
            $unique[$key] = $attempt;
        }

        return array_values($unique);
    }

    private function isUnavailableClientResourceError(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'recurso')
            && str_contains($normalized, 'cliente')
            && (
                str_contains($normalized, 'nao esta disponivel')
                || str_contains($normalized, 'nÃ£o estÃ¡ disponÃ­vel')
            );
    }
    /**
     * @return array{items: array<int, array<string,mixed>>, total: int, page: int, per_page: int}|null
     */
    private function searchClientsWithAlternativeResources(Company $company, string $query, int $page, int $perPage): ?array
    {
        $query = trim($query);
        $queryDigits = preg_replace('/\D+/', '', $query) ?? '';
        $documentVariants = $this->buildDocumentVariants($query, $queryDigits);
        $resources = $this->resolveAlternativeClientResources();
        if ($resources === []) {
            return null;
        }

        $baseListParams = [
            'page' => $page,
            'rp' => $perPage,
            'sortorder' => 'asc',
        ];
        $attempts = [];

        foreach ($resources as $resource) {
            $resourceNormalized = strtolower(trim($resource));
            if ($resourceNormalized === '') {
                continue;
            }

            if ($resourceNormalized === 'listar_clientes_por_cpf') {
                if ($queryDigits === '') {
                    continue;
                }

                foreach ($documentVariants as $variant) {
                    $attempts[] = ['resource' => $resourceNormalized, 'params' => ['cpf' => $variant]];
                    $attempts[] = ['resource' => $resourceNormalized, 'params' => ['cpf_cnpj' => $variant]];
                    $attempts[] = ['resource' => $resourceNormalized, 'params' => ['cnpj_cpf' => $variant]];
                    $attempts[] = ['resource' => $resourceNormalized, 'params' => array_merge($baseListParams, [
                        'qtype' => 'cliente.cnpj_cpf',
                        'query' => $variant,
                        'oper' => '=',
                        'sortname' => 'cliente.id',
                    ])];
                }

                continue;
            }

            if ($resourceNormalized === 'listar_clientes_por_telefone') {
                if ($queryDigits === '') {
                    continue;
                }

                $attempts[] = ['resource' => $resourceNormalized, 'params' => ['telefone' => $queryDigits]];
                $attempts[] = ['resource' => $resourceNormalized, 'params' => ['phone' => $queryDigits]];
                $attempts[] = ['resource' => $resourceNormalized, 'params' => array_merge($baseListParams, [
                    'qtype' => 'cliente.telefone_celular',
                    'query' => $queryDigits,
                    'oper' => 'L',
                    'sortname' => 'cliente.id',
                ])];

                continue;
            }

            if ($resourceNormalized === 'listar_clientes_fibra') {
                $params = $baseListParams;
                if ($query !== '') {
                    $params = array_merge($params, [
                        'qtype' => 'cliente.razao',
                        'query' => $query,
                        'oper' => 'L',
                        'sortname' => 'cliente.razao',
                    ]);
                }
                $attempts[] = ['resource' => $resourceNormalized, 'params' => $params];
                continue;
            }

            $attempts[] = ['resource' => $resourceNormalized, 'params' => $baseListParams];
        }

        foreach ($attempts as $attempt) {
            try {
                $payload = $this->ixcApi->request($company, (string) $attempt['resource'], (array) $attempt['params']);
                $list = $this->ixcApi->normalizeList($payload, $page, $perPage);
            } catch (RuntimeException) {
                continue;
            }

            if (($list['total'] ?? 0) > 0 || count($list['items'] ?? []) > 0) {
                Log::info('ixc.client.search.alt_resource.hit', [
                    'company_id' => (int) $company->id,
                    'resource' => (string) $attempt['resource'],
                    'query' => $query === '' ? '[empty]' : $query,
                ]);
                return $list;
            }
        }

        Log::info('ixc.client.search.alt_resource.empty', [
            'company_id' => (int) $company->id,
            'query' => $query === '' ? '[empty]' : $query,
            'resources' => array_values($resources),
        ]);

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function resolveAlternativeClientResources(): array
    {
        $configured = config('ixc.client_alternative_resources', []);
        if (! is_array($configured)) {
            return [];
        }

        $resources = [];
        foreach ($configured as $resource) {
            $normalized = strtolower(trim((string) $resource));
            if ($normalized === '') {
                continue;
            }
            $resources[] = $normalized;
        }

        return array_values(array_unique($resources));
    }

    /**
     * @return array<int, string>
     */
    private function buildDocumentVariants(string $rawQuery, string $queryDigits): array
    {
        $variants = [];

        $push = static function (string $value) use (&$variants): void {
            $trimmed = trim($value);
            if ($trimmed !== '') {
                $variants[] = $trimmed;
            }
        };

        $push($rawQuery);
        $push($queryDigits);

        if ($this->isCompleteCpfOrCnpj($queryDigits)) {
            $push($this->formatCpfOrCnpj($queryDigits));
        }

        $unique = [];
        foreach ($variants as $variant) {
            $unique[mb_strtolower($variant)] = $variant;
        }

        return array_values($unique);
    }

    private function isCompleteCpfOrCnpj(string $queryDigits): bool
    {
        return in_array(strlen($queryDigits), [11, 14], true);
    }

    private function formatCpfOrCnpj(string $queryDigits): string
    {
        if (strlen($queryDigits) === 11) {
            return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $queryDigits) ?: $queryDigits;
        }

        if (strlen($queryDigits) === 14) {
            return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $queryDigits) ?: $queryDigits;
        }

        return $queryDigits;
    }

    private function friendlyIxcErrorMessage(string $message, string $fallback = 'Nao foi possivel concluir a consulta na IXC.'): string
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return $fallback;
        }

        if (preg_match('/http\\s*404/i', $normalized) === 1) {
            return 'Nao foi possivel localizar o recurso de clientes na IXC com este usuario/token.';
        }

        if (str_contains($normalized, 'falha de conex')) {
            return 'Nao foi possivel conectar na IXC no momento. Tente novamente em instantes.';
        }

        if (str_contains($normalized, 'resposta invalida')) {
            return 'A IXC retornou uma resposta inesperada para a consulta de clientes.';
        }

        if (str_contains($normalized, 'temporariamente indispon')) {
            return 'A integracao IXC esta temporariamente indisponivel para esta empresa.';
        }

        if ($this->isUnavailableClientResourceError($message)) {
            return 'A listagem de clientes nao esta habilitada no IXC para este usuario/token.';
        }

        return $message;
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
                'message' => $this->friendlyIxcErrorMessage(
                    $exception->getMessage(),
                    'Nao foi possivel consultar o cliente na IXC agora.'
                ),
            ], 422);
        }

        $client = $list['items'][0] ?? null;
        if (! is_array($client)) {
            return response()->json([
                'ok' => false,
                'message' => 'Cliente nÃ£o encontrado na IXC.',
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
                'message' => $this->friendlyIxcErrorMessage(
                    $exception->getMessage(),
                    'Nao foi possivel consultar boletos na IXC agora.'
                ),
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

    public function fiscalNotes(Request $request, string $clientId): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(200, max(10, (int) $request->integer('per_page', 30)));

        $params = [
            'qtype' => 'fn_areceber.id_cliente',
            'query' => (string) $clientId,
            'oper' => '=',
            'page' => 1,
            'rp' => 200,
            'sortname' => 'fn_areceber.id',
            'sortorder' => 'desc',
        ];

        try {
            $payload = $this->ixcApi->request($company, 'fn_areceber', $params);
            $list = $this->ixcApi->normalizeList($payload, 1, 200);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $this->friendlyIxcErrorMessage(
                    $exception->getMessage(),
                    'Nao foi possivel consultar notas fiscais na IXC agora.'
                ),
            ], 422);
        }

        $grouped = [];
        foreach ($list['items'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $noteId = $this->extractInvoiceNoteId($row);
            if ($noteId === '') {
                continue;
            }
            if (! isset($grouped[$noteId])) {
                $grouped[$noteId] = $row;
            }
        }

        $items = array_values(array_map(fn(array $row) => $this->mapFiscalNoteItem($row), $grouped));
        $total = count($items);
        $offset = ($page - 1) * $perPage;
        $pagedItems = array_slice($items, $offset, $perPage);

        return response()->json([
            'ok' => true,
            'client_id' => (int) $clientId,
            'items' => $pagedItems,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_next' => ($page * $perPage) < $total,
            ],
        ]);
    }

    public function fiscalNoteDetail(Request $request, string $clientId, string $noteId): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $invoiceRow = $this->loadInvoiceRowByFiscalNoteId($company, $clientId, $noteId);
        if (! is_array($invoiceRow)) {
            return response()->json(['ok' => false, 'message' => 'Nota fiscal nao encontrada.'], 404);
        }

        return response()->json([
            'ok' => true,
            'item' => $this->mapFiscalNoteItem($invoiceRow),
        ]);
    }

    public function downloadFiscalNote(Request $request, string $clientId, string $noteId): Response
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $invoiceRow = $this->loadInvoiceRowByFiscalNoteId($company, $clientId, $noteId);
        if (! is_array($invoiceRow)) {
            return response()->json(['ok' => false, 'message' => 'Nota fiscal nao encontrada.'], 404);
        }

        try {
            $binaryPayload = $this->resolveFiscalNoteBinary($company, $invoiceRow, $noteId);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $this->friendlyIxcErrorMessage(
                    $exception->getMessage(),
                    'Nao foi possivel obter o arquivo da nota fiscal na IXC.'
                ),
            ], 422);
        }

        $filename = "nota_fiscal_{$noteId}.pdf";
        $contentType = (string) ($binaryPayload['content_type'] ?? 'application/pdf');

        return response((string) $binaryPayload['binary'], 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . addslashes($filename) . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function sendFiscalNoteEmail(Request $request, string $clientId, string $noteId): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:190'],
        ]);

        $invoiceRow = $this->loadInvoiceRowByFiscalNoteId($company, $clientId, $noteId);
        if (! is_array($invoiceRow)) {
            return response()->json(['ok' => false, 'message' => 'Nota fiscal nao encontrada.'], 404);
        }

        try {
            $sendResult = $this->sendFiscalNoteThroughIxc($company, $invoiceRow, $clientId, $noteId, 'email', [
                'email' => (string) $validated['email'],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $this->friendlyIxcErrorMessage(
                    $exception->getMessage(),
                    'Nao foi possivel enviar a nota fiscal por e-mail na IXC.'
                ),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Nota fiscal enviada por e-mail com sucesso.',
            'provider_status' => $sendResult['provider_status'] ?? 'ok',
        ]);
    }

    public function sendFiscalNoteSms(Request $request, string $clientId, string $noteId): JsonResponse
    {
        $company = $this->resolveCompany($request);
        if ($company instanceof JsonResponse) {
            return $company;
        }

        $validated = $request->validate([
            'phone' => ['required', 'string', 'min:8', 'max:25'],
        ]);

        $invoiceRow = $this->loadInvoiceRowByFiscalNoteId($company, $clientId, $noteId);
        if (! is_array($invoiceRow)) {
            return response()->json(['ok' => false, 'message' => 'Nota fiscal nao encontrada.'], 404);
        }

        try {
            $sendResult = $this->sendFiscalNoteThroughIxc($company, $invoiceRow, $clientId, $noteId, 'sms', [
                'phone' => (string) $validated['phone'],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $this->friendlyIxcErrorMessage(
                    $exception->getMessage(),
                    'Nao foi possivel enviar a nota fiscal por SMS na IXC.'
                ),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Nota fiscal enviada por SMS com sucesso.',
            'provider_status' => $sendResult['provider_status'] ?? 'ok',
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
            return response()->json(['ok' => false, 'message' => 'Boleto nÃ£o encontrado.'], 404);
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
            return response()->json(['ok' => false, 'message' => 'Boleto nÃ£o encontrado.'], 404);
        }

        try {
            $binaryPayload = $this->resolveInvoiceBinary($company, $invoice, $clientId, $invoiceId);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $this->friendlyIxcErrorMessage(
                    $exception->getMessage(),
                    'Nao foi possivel obter o arquivo do boleto na IXC.'
                ),
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
            return response()->json(['ok' => false, 'message' => 'Boleto nÃ£o encontrado.'], 404);
        }

        try {
            $sendResult = $this->sendInvoiceThroughIxc($company, $clientId, $invoiceId, 'email', [
                'email' => (string) $validated['email'],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $this->friendlyIxcErrorMessage(
                    $exception->getMessage(),
                    'Nao foi possivel enviar o boleto por e-mail na IXC.'
                ),
            ], 422);
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
            return response()->json(['ok' => false, 'message' => 'Boleto nÃ£o encontrado.'], 404);
        }

        try {
            $sendResult = $this->sendInvoiceThroughIxc($company, $clientId, $invoiceId, 'sms', [
                'phone' => (string) $validated['phone'],
            ]);
        } catch (RuntimeException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $this->friendlyIxcErrorMessage(
                    $exception->getMessage(),
                    'Nao foi possivel enviar o boleto por SMS na IXC.'
                ),
            ], 422);
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
            return response()->json(['ok' => false, 'message' => 'Empresa nÃ£o encontrada.'], 404);
        }

        $company = Company::find($companyId);
        if (! $company) {
            return response()->json(['ok' => false, 'message' => 'Empresa nÃ£o encontrada.'], 404);
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
        $statusCode = strtoupper(trim((string) ($row['status'] ?? '')));
        return [
            'id' => (int) ($row['id'] ?? 0),
            'id_cliente' => (int) ($row['id_cliente'] ?? 0),
            'status' => $statusCode,
            'status_label' => $this->invoiceStatusLabel($statusCode),
            'valor' => (string) ($row['valor'] ?? $row['valor_parcela'] ?? ''),
            'data_vencimento' => (string) ($row['data_vencimento'] ?? ''),
            'linha_digitavel' => (string) ($row['linha_digitavel'] ?? ''),
            'documento' => (string) ($row['documento'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function mapFiscalNoteItem(array $row): array
    {
        $noteId = $this->extractInvoiceNoteId($row);
        $statusCode = strtoupper(trim((string) ($row['status'] ?? '')));

        return [
            'id' => (int) $noteId,
            'id_cliente' => (int) ($row['id_cliente'] ?? 0),
            'id_financeiro' => (int) ($row['id'] ?? 0),
            'status' => $statusCode,
            'status_label' => $this->invoiceStatusLabel($statusCode),
            'data_emissao' => (string) ($row['data_emissao'] ?? ''),
            'data_vencimento' => (string) ($row['data_vencimento'] ?? ''),
            'valor' => (string) ($row['valor'] ?? $row['valor_parcela'] ?? ''),
            'documento' => (string) ($row['documento'] ?? ''),
        ];
    }

    private function invoiceStatusLabel(string $statusCode): string
    {
        $map = [
            'A' => 'Aberto',
            'P' => 'Pago',
            'C' => 'Cancelado',
            'R' => 'Renegociado',
            'V' => 'Vencido',
            'N' => 'Negativado',
            'B' => 'Baixado',
        ];

        return $map[$statusCode] ?? ($statusCode !== '' ? $statusCode : '-');
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

    private function loadInvoiceRowByFiscalNoteId(Company $company, string $clientId, string $noteId): ?array
    {
        $params = [
            'qtype' => 'fn_areceber.id_cliente',
            'query' => (string) $clientId,
            'oper' => '=',
            'page' => 1,
            'rp' => 200,
            'sortname' => 'fn_areceber.id',
            'sortorder' => 'desc',
        ];

        try {
            $payload = $this->ixcApi->request($company, 'fn_areceber', $params);
            $list = $this->ixcApi->normalizeList($payload, 1, 200);
        } catch (RuntimeException) {
            return null;
        }

        foreach ($list['items'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($this->extractInvoiceNoteId($row) === (string) $noteId) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function extractInvoiceNoteId(array $row): string
    {
        $candidates = [
            (string) ($row['id_nota_gerada'] ?? ''),
            (string) ($row['id_nota_gerada_opc2'] ?? ''),
            (string) ($row['id_nota_gerada_opc3'] ?? ''),
            (string) ($row['id_nota_gerada_opc4'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $value = trim($candidate);
            if ($value !== '' && $value !== '0') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $invoiceRow
     * @return array{binary:string, content_type:string}
     */
    private function resolveFiscalNoteBinary(Company $company, array $invoiceRow, string $noteId): array
    {
        $invoiceId = trim((string) ($invoiceRow['id'] ?? ''));
        $clientId = trim((string) ($invoiceRow['id_cliente'] ?? ''));
        $baseHost = strtolower((string) (parse_url((string) ($company->ixc_base_url ?? ''), PHP_URL_HOST) ?? ''));
        $allowPrivateHosts = (bool) config('ixc.allow_private_hosts', false);
        $lastError = null;

        Log::info('ixc.fiscal_note.download.start', [
            'company_id' => (int) $company->id,
            'note_id' => $noteId,
            'invoice_id' => $invoiceId,
            'client_id' => $clientId,
        ]);

        $fromInvoice = $this->extractBinaryFromPayload($company, $invoiceRow, $baseHost, $allowPrivateHosts);
        if ($fromInvoice !== null) {
            Log::info('ixc.fiscal_note.download.ok', [
                'company_id' => (int) $company->id,
                'note_id' => $noteId,
                'source' => 'invoice_row',
            ]);
            return $fromInvoice;
        }

        $attempts = [
            ['resource' => 'nota_fiscal', 'params' => ['id' => $noteId]],
            ['resource' => 'nota_fiscal_saida', 'params' => ['id' => $noteId]],
            ['resource' => 'nfe', 'params' => ['id' => $noteId]],
        ];

        foreach ($attempts as $attempt) {
            try {
                $payload = $this->ixcApi->request($company, (string) $attempt['resource'], (array) $attempt['params']);
                $fromPayload = $this->extractBinaryFromPayload($company, $payload, $baseHost, $allowPrivateHosts);
                if ($fromPayload !== null) {
                    Log::info('ixc.fiscal_note.download.ok', [
                        'company_id' => (int) $company->id,
                        'note_id' => $noteId,
                        'source' => (string) $attempt['resource'],
                    ]);
                    return $fromPayload;
                }
                $providerMessage = $this->extractProviderMessageFromPayload($payload);
                if ($providerMessage !== null) {
                    $lastError = $providerMessage;
                }
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
                if (! $this->isUnavailableIxcResourceError($message)) {
                    $lastError = $message;
                }
                Log::info('ixc.fiscal_note.download.attempt_failed', [
                    'company_id' => (int) $company->id,
                    'note_id' => $noteId,
                    'resource' => (string) $attempt['resource'],
                    'error' => $message,
                ]);
            }

            try {
                $binary = $this->ixcApi->requestBinary($company, (string) $attempt['resource'], (array) $attempt['params']);
                $body = (string) ($binary['body'] ?? '');
                if ($body !== '') {
                    Log::info('ixc.fiscal_note.download.ok', [
                        'company_id' => (int) $company->id,
                        'note_id' => $noteId,
                        'source' => (string) $attempt['resource'] . '_binary',
                    ]);
                    return [
                        'binary' => $body,
                        'content_type' => (string) ($binary['content_type'] ?? 'application/pdf'),
                    ];
                }
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
                if (! $this->isUnavailableIxcResourceError($message)) {
                    $lastError = $message;
                }
                Log::info('ixc.fiscal_note.download.attempt_failed', [
                    'company_id' => (int) $company->id,
                    'note_id' => $noteId,
                    'resource' => (string) $attempt['resource'] . '_binary',
                    'error' => $message,
                ]);
            }
        }

        // Mantém exatamente o mesmo comportamento já validado do boleto.
        if ($invoiceId !== '' && $invoiceId !== '0' && $clientId !== '' && $clientId !== '0') {
            try {
                $fallback = $this->resolveInvoiceBinary($company, $invoiceRow, $clientId, $invoiceId);
                Log::info('ixc.fiscal_note.download.ok', [
                    'company_id' => (int) $company->id,
                    'note_id' => $noteId,
                    'source' => 'invoice_fallback',
                ]);

                return $fallback;
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
                if (! $this->isUnavailableIxcResourceError($message)) {
                    $lastError = $message;
                }
                Log::warning('ixc.fiscal_note.download.fallback_failed', [
                    'company_id' => (int) $company->id,
                    'note_id' => $noteId,
                    'invoice_id' => $invoiceId,
                    'client_id' => $clientId,
                    'error' => $message,
                ]);
            }
        }

        throw new RuntimeException($lastError ?: 'Nao foi possivel obter o arquivo da nota fiscal na IXC.');
    }

    /**
     * @param  array<string, mixed>  $invoiceRow
     * @param  array<string, string>  $destination
     * @return array<string, mixed>
     */
    private function sendFiscalNoteThroughIxc(
        Company $company,
        array $invoiceRow,
        string $clientId,
        string $noteId,
        string $channel,
        array $destination
    ): array {
        $invoiceId = (string) ($invoiceRow['id'] ?? '');
        Log::info('ixc.fiscal_note.send.start', [
            'company_id' => (int) $company->id,
            'note_id' => $noteId,
            'invoice_id' => $invoiceId,
            'channel' => $channel,
        ]);

        // Alinha ao mesmo fluxo do boleto (já validado): evita chamadas fiscais que
        // podem cair em validação de período fechado.
        if ($invoiceId !== '' && $invoiceId !== '0') {
            $result = $this->sendInvoiceThroughIxc($company, $clientId, $invoiceId, $channel, $destination);
            Log::info('ixc.fiscal_note.send.ok', [
                'company_id' => (int) $company->id,
                'note_id' => $noteId,
                'invoice_id' => $invoiceId,
                'channel' => $channel,
                'source' => 'invoice_fallback',
            ]);

            return $result;
        }

        throw new RuntimeException('Nao foi possivel enviar nota fiscal pela IXC: titulo financeiro nao identificado.');
    }

    private function isUnavailableIxcResourceError(string $message): bool
    {
        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'recurso')
            && (
                str_contains($normalized, 'nao esta disponivel')
                || str_contains($normalized, 'nÃƒÂ£o estÃƒÂ¡ disponÃƒÂ­vel')
                || str_contains($normalized, 'nÃ£o estÃ¡ disponÃ­vel')
            );
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array{binary:string, content_type:string}
     */
    private function resolveInvoiceBinary(Company $company, array $invoice, string $clientId, string $invoiceId): array
    {
        $lastError = null;
        $baseHost = strtolower((string) (parse_url((string) ($company->ixc_base_url ?? ''), PHP_URL_HOST) ?? ''));
        $allowPrivateHosts = (bool) config('ixc.allow_private_hosts', false);

        $fromInvoice = $this->extractBinaryFromPayload($company, $invoice, $baseHost, $allowPrivateHosts);
        if ($fromInvoice !== null) {
            return $fromInvoice;
        }

        try {
            $jsonAttempt = $this->ixcApi->request($company, 'get_boleto', [
                'id' => $invoiceId,
                'id_cliente' => $clientId,
            ]);

            $fromJson = $this->extractBinaryFromPayload($company, $jsonAttempt, $baseHost, $allowPrivateHosts);
            if ($fromJson !== null) {
                return $fromJson;
            }

            $providerMessage = $this->extractProviderMessageFromPayload($jsonAttempt);
            if ($providerMessage !== null) {
                $lastError = $providerMessage;
            }
        } catch (RuntimeException $exception) {
            $lastError = $exception->getMessage();
        }

        try {
            $binaryAttempt = $this->ixcApi->requestBinary($company, 'get_boleto', [
                'id' => $invoiceId,
                'id_cliente' => $clientId,
            ]);
            $body = (string) ($binaryAttempt['body'] ?? '');
            if ($body !== '') {
                $contentType = strtolower((string) ($binaryAttempt['content_type'] ?? ''));
                if (str_contains($contentType, 'json') || str_starts_with(trim($body), '{')) {
                    $decoded = json_decode($body, true);
                    if (is_array($decoded)) {
                        $fromJson = $this->extractBinaryFromPayload($company, $decoded, $baseHost, $allowPrivateHosts);
                        if ($fromJson !== null) {
                            return $fromJson;
                        }
                        $providerMessage = $this->extractProviderMessageFromPayload($decoded);
                        if ($providerMessage !== null) {
                            $lastError = $providerMessage;
                        }
                    }
                }

                return [
                    'binary' => $body,
                    'content_type' => (string) ($binaryAttempt['content_type'] ?? 'application/pdf'),
                ];
            }
        } catch (RuntimeException $exception) {
            $lastError = $exception->getMessage();
        }

        throw new RuntimeException($lastError ?: 'NÃ£o foi possÃ­vel obter o arquivo do boleto na IXC.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{binary:string, content_type:string}|null
     */
    private function extractBinaryFromPayload(Company $company, array $payload, string $baseHost, bool $allowPrivateHosts): ?array
    {
        $base64Keys = ['pdf_base64', 'arquivo_base64', 'boleto_base64', 'pdf'];
        foreach ($base64Keys as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
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

        $urlKeys = ['url', 'link', 'link_boleto', 'boleto_link', 'pdf_url', 'arquivo_url'];
        foreach ($urlKeys as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
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

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractProviderMessageFromPayload(array $payload): ?string
    {
        $candidates = [
            $payload['message'] ?? null,
            $payload['mensagem'] ?? null,
            $payload['error'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $message = trim((string) $candidate);
            if ($message !== '') {
                return $message;
            }
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

        throw new RuntimeException($lastError ?: 'NÃ£o foi possÃ­vel enviar boleto pela IXC.');
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


