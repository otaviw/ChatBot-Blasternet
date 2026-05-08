<?php

declare(strict_types=1);

namespace App\Services\Bot\Handlers;

use App\Models\Company;
use App\Models\Conversation;
use App\Services\Bot\BotFlowRegistry;
use App\Services\IxcApiService;
use App\Services\WhatsApp\WhatsAppSendService;
use App\Support\Enums\BotFlow;
use App\Support\IxcUrlGuard;
use App\Support\PhoneNumberNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class IxcInvoiceFlowHandler
{
    use BotHandlerHelpers;

    private const STEP_ASK_DOCUMENT = 'ask_document';
    private const STEP_CHOOSE_CLIENT = 'choose_client';
    private const STEP_CHOOSE_INVOICE = 'choose_invoice';
    private const STEP_CONFIRM_INVOICE = 'confirm_invoice';
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly BotFlowRegistry $registry,
        private readonly IxcApiService $ixcApi,
        private readonly WhatsAppSendService $whatsAppSend,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function start(?Company $company, Conversation $conversation, array $action): array
    {
        $companyEntity = $this->resolveCompany($company, $conversation);
        $handoffArea = trim((string) ($action['target_area_name'] ?? BotFlowRegistry::AREA_ATTENDANCE));
        if ($handoffArea === '') {
            $handoffArea = BotFlowRegistry::AREA_ATTENDANCE;
        }

        if (! $companyEntity || ! $companyEntity->hasIxcIntegration()) {
            return $this->handoffResult(
                $companyEntity,
                $conversation,
                'Não consegui acessar a integração de boletos agora. Vou te encaminhar para um atendente.',
                $handoffArea
            );
        }

        $prompt = "Perfeito. Para consultar boletos em aberto, informe o CPF/CNPJ somente com números.\nExemplo CPF: 12345678901\nExemplo CNPJ: 12345678000199";

        return $this->botStateResult($prompt, [
            'flow' => BotFlow::IXC_INVOICES->value,
            'step' => self::STEP_ASK_DOCUMENT,
            'context' => [
                'attempts' => 0,
                'handoff_area' => $handoffArea,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(
        ?Company $company,
        Conversation $conversation,
        string $step,
        string $normalizedText,
        bool $sendOutbound = true
    ): array {
        $companyEntity = $this->resolveCompany($company, $conversation);
        if (! $companyEntity) {
            return $this->notHandled();
        }

        $context = is_array($conversation->bot_context ?? null) ? $conversation->bot_context : [];
        $handoffArea = $this->resolveHandoffArea($context);

        return match ($step) {
            self::STEP_ASK_DOCUMENT => $this->handleAskDocument($companyEntity, $conversation, $normalizedText, $context, $handoffArea, $sendOutbound),
            self::STEP_CHOOSE_CLIENT => $this->handleChooseClient($companyEntity, $conversation, $normalizedText, $context, $handoffArea, $sendOutbound),
            self::STEP_CHOOSE_INVOICE => $this->handleChooseInvoice($companyEntity, $conversation, $normalizedText, $context, $handoffArea, $sendOutbound),
            self::STEP_CONFIRM_INVOICE => $this->handleConfirmInvoice($companyEntity, $conversation, $normalizedText, $context, $handoffArea, $sendOutbound),
            default => $this->notHandled(),
        };
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleAskDocument(
        Company $company,
        Conversation $conversation,
        string $input,
        array $context,
        string $handoffArea,
        bool $sendOutbound
    ): array {
        $document = preg_replace('/\D+/', '', $input) ?? '';
        if (! in_array(strlen($document), [11, 14], true)) {
            return $this->invalidAttempt(
                $company,
                $conversation,
                self::STEP_ASK_DOCUMENT,
                $context,
                "Documento inválido. Informe um CPF (11 dígitos) ou CNPJ (14 dígitos).",
                $handoffArea
            );
        }

        try {
            $searchResult = $this->searchClientsByDocument($company, $document, (string) ($conversation->customer_phone ?? ''));
            $clients = is_array($searchResult['clients'] ?? null) ? $searchResult['clients'] : [];
            $phoneMismatch = (bool) ($searchResult['phone_mismatch'] ?? false);
        } catch (RuntimeException $exception) {
            return $this->handoffResult(
                $company,
                $conversation,
                'Tive um problema para consultar boletos na IXC. Vou te encaminhar para um atendente.',
                $handoffArea
            );
        }

        if ($clients === []) {
            if ($phoneMismatch) {
                return $this->invalidAttempt(
                    $company,
                    $conversation,
                    self::STEP_ASK_DOCUMENT,
                    $context,
                    'Encontrei cadastro para esse CPF/CNPJ, mas este numero de WhatsApp nao confere com o telefone registrado. Confira os dados ou fale com um atendente.',
                    $handoffArea
                );
            }

            return $this->invalidAttempt(
                $company,
                $conversation,
                self::STEP_ASK_DOCUMENT,
                $context,
                'Não encontrei cliente para esse CPF/CNPJ. Confira e tente novamente.',
                $handoffArea
            );
        }

        if (count($clients) === 1) {
            return $this->handleClientResolved($company, $conversation, $context, $clients[0], $handoffArea, $sendOutbound);
        }

        $options = [];
        $lines = ['Encontrei mais de um cliente para esse documento. Escolha um número:'];
        foreach (array_slice($clients, 0, 10) as $index => $client) {
            $key = (string) ($index + 1);
            $options[$key] = [
                'id' => (int) ($client['id'] ?? 0),
                'label' => (string) ($client['label'] ?? 'Cliente'),
            ];
            $lines[] = "{$key} - {$options[$key]['label']}";
        }

        $replyText = implode("\n", $lines);

        return $this->botStateResult($replyText, [
            'flow' => BotFlow::IXC_INVOICES->value,
            'step' => self::STEP_CHOOSE_CLIENT,
            'context' => array_merge($context, [
                'attempts' => 0,
                'handoff_area' => $handoffArea,
                'document' => $document,
                'client_options' => $options,
            ]),
        ], $this->buildChoiceMessage($replyText, $options, 'Escolher cliente'));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleChooseClient(
        Company $company,
        Conversation $conversation,
        string $input,
        array $context,
        string $handoffArea,
        bool $sendOutbound
    ): array {
        $options = is_array($context['client_options'] ?? null) ? $context['client_options'] : [];
        if ($options === []) {
            return $this->botStateResult(
                'Vamos reiniciar a consulta de boleto. Informe o CPF/CNPJ novamente.',
                [
                    'flow' => BotFlow::IXC_INVOICES->value,
                    'step' => self::STEP_ASK_DOCUMENT,
                    'context' => [
                        'attempts' => 0,
                        'handoff_area' => $handoffArea,
                    ],
                ]
            );
        }

        $selectedKey = $this->resolveSelectionKey($input, $options);
        if ($selectedKey === null) {
            return $this->invalidAttempt(
                $company,
                $conversation,
                self::STEP_CHOOSE_CLIENT,
                $context,
                'Opção inválida. Responda com o número do cliente desejado.',
                $handoffArea
            );
        }

        $selected = is_array($options[$selectedKey] ?? null) ? $options[$selectedKey] : null;
        if (! is_array($selected) || (int) ($selected['id'] ?? 0) <= 0) {
            return $this->invalidAttempt(
                $company,
                $conversation,
                self::STEP_CHOOSE_CLIENT,
                $context,
                'Não consegui identificar o cliente escolhido. Tente novamente.',
                $handoffArea
            );
        }

        return $this->handleClientResolved($company, $conversation, $context, $selected, $handoffArea, $sendOutbound);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $selectedClient
     * @return array<string, mixed>
     */
    private function handleClientResolved(
        Company $company,
        Conversation $conversation,
        array $context,
        array $selectedClient,
        string $handoffArea,
        bool $sendOutbound
    ): array {
        $clientId = (string) ((int) ($selectedClient['id'] ?? 0));
        if ($clientId === '0') {
            return $this->invalidAttempt(
                $company,
                $conversation,
                self::STEP_ASK_DOCUMENT,
                $context,
                'Cliente inválido. Informe o CPF/CNPJ novamente.',
                $handoffArea
            );
        }

        try {
            $invoices = $this->listOpenInvoices($company, $clientId);
        } catch (RuntimeException) {
            return $this->handoffResult(
                $company,
                $conversation,
                'Não consegui listar os boletos na IXC. Vou te encaminhar para um atendente.',
                $handoffArea
            );
        }

        if ($invoices === []) {
            return $this->returnToMainMenu(
                $company,
                'Não encontrei boletos em aberto para esse cadastro no momento.'
            );
        }

        if (count($invoices) === 1) {
            return $this->prepareInvoiceConfirmation(
                $company,
                $clientId,
                $invoices[0],
                $context,
                $handoffArea
            );
        }

        $options = [];
        $lines = ['Encontrei mais de um boleto em aberto. Escolha um número:'];
        foreach (array_slice($invoices, 0, 10) as $index => $invoice) {
            $key = (string) ($index + 1);
            $options[$key] = [
                'id' => (int) ($invoice['id'] ?? 0),
                'label' => (string) ($invoice['label'] ?? "Boleto {$key}"),
                'data_vencimento' => (string) ($invoice['data_vencimento'] ?? ''),
                'valor' => (string) ($invoice['valor'] ?? ''),
            ];
            $lines[] = "{$key} - {$options[$key]['label']}";
        }

        $replyText = implode("\n", $lines);

        return $this->botStateResult($replyText, [
            'flow' => BotFlow::IXC_INVOICES->value,
            'step' => self::STEP_CHOOSE_INVOICE,
            'context' => array_merge($context, [
                'attempts' => 0,
                'handoff_area' => $handoffArea,
                'selected_client_id' => (int) $clientId,
                'invoice_options' => $options,
            ]),
        ], $this->buildChoiceMessage($replyText, $options, 'Escolher boleto'));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleChooseInvoice(
        Company $company,
        Conversation $conversation,
        string $input,
        array $context,
        string $handoffArea,
        bool $sendOutbound
    ): array {
        $clientId = (int) ($context['selected_client_id'] ?? 0);
        $options = is_array($context['invoice_options'] ?? null) ? $context['invoice_options'] : [];
        if ($clientId <= 0 || $options === []) {
            return $this->botStateResult(
                'Vamos reiniciar a consulta de boleto. Informe o CPF/CNPJ novamente.',
                [
                    'flow' => BotFlow::IXC_INVOICES->value,
                    'step' => self::STEP_ASK_DOCUMENT,
                    'context' => [
                        'attempts' => 0,
                        'handoff_area' => $handoffArea,
                    ],
                ]
            );
        }

        $selectedKey = $this->resolveSelectionKey($input, $options);
        if ($selectedKey === null) {
            return $this->invalidAttempt(
                $company,
                $conversation,
                self::STEP_CHOOSE_INVOICE,
                $context,
                'Opção inválida. Responda com o número do boleto desejado.',
                $handoffArea
            );
        }

        $invoice = is_array($options[$selectedKey] ?? null) ? $options[$selectedKey] : null;
        if (! is_array($invoice) || (int) ($invoice['id'] ?? 0) <= 0) {
            return $this->invalidAttempt(
                $company,
                $conversation,
                self::STEP_CHOOSE_INVOICE,
                $context,
                'Não consegui identificar o boleto escolhido. Tente novamente.',
                $handoffArea
            );
        }

        return $this->prepareInvoiceConfirmation(
            $company,
            (string) $clientId,
            $invoice,
            $context,
            $handoffArea
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function handleConfirmInvoice(
        Company $company,
        Conversation $conversation,
        string $input,
        array $context,
        string $handoffArea,
        bool $sendOutbound
    ): array {
        $clientId = (int) ($context['selected_client_id'] ?? 0);
        $invoice = is_array($context['selected_invoice'] ?? null) ? $context['selected_invoice'] : [];
        $invoiceId = (int) ($invoice['id'] ?? 0);

        if ($clientId <= 0 || $invoiceId <= 0) {
            return $this->botStateResult(
                'Vamos reiniciar a consulta de boleto. Informe o CPF/CNPJ novamente.',
                [
                    'flow' => BotFlow::IXC_INVOICES->value,
                    'step' => self::STEP_ASK_DOCUMENT,
                    'context' => [
                        'attempts' => 0,
                        'handoff_area' => $handoffArea,
                    ],
                ]
            );
        }

        if ($this->isConfirmInput($input)) {
            return $this->sendSelectedInvoice(
                $company,
                $conversation,
                (string) $clientId,
                $invoice,
                $sendOutbound,
                $handoffArea
            );
        }

        if ($this->isCancelInput($input)) {
            return $this->returnToMainMenu(
                $company,
                'Sem problemas. Se quiser, posso te ajudar com outra opcao.'
            );
        }

        return $this->invalidAttempt(
            $company,
            $conversation,
            self::STEP_CONFIRM_INVOICE,
            $context,
            'Opcao invalida. Responda 1 para confirmar o envio do boleto ou 2 para cancelar.',
            $handoffArea
        );
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function prepareInvoiceConfirmation(
        Company $company,
        string $clientId,
        array $invoice,
        array $context,
        string $handoffArea
    ): array {
        $invoiceId = (int) ($invoice['id'] ?? 0);
        if ($invoiceId <= 0) {
            return $this->botStateResult(
                'Nao consegui identificar o boleto. Informe o CPF/CNPJ novamente.',
                [
                    'flow' => BotFlow::IXC_INVOICES->value,
                    'step' => self::STEP_ASK_DOCUMENT,
                    'context' => [
                        'attempts' => 0,
                        'handoff_area' => $handoffArea,
                    ],
                ]
            );
        }

        $invoiceSummary = trim((string) ($invoice['label'] ?? "Boleto {$invoiceId}"));
        $replyText = "Voce escolheu {$invoiceSummary}.\nDeseja que eu envie esse boleto agora?\n1 - Confirmar envio\n2 - Cancelar";

        return $this->botStateResult($replyText, [
            'flow' => BotFlow::IXC_INVOICES->value,
            'step' => self::STEP_CONFIRM_INVOICE,
            'context' => array_merge($context, [
                'attempts' => 0,
                'handoff_area' => $handoffArea,
                'selected_client_id' => (int) $clientId,
                'selected_invoice' => [
                    'id' => $invoiceId,
                    'label' => $invoiceSummary,
                    'data_vencimento' => (string) ($invoice['data_vencimento'] ?? ''),
                    'valor' => (string) ($invoice['valor'] ?? ''),
                ],
            ]),
        ], $this->buildConfirmMessage($replyText));
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    private function sendSelectedInvoice(
        Company $company,
        Conversation $conversation,
        string $clientId,
        array $invoice,
        bool $sendOutbound,
        string $handoffArea
    ): array {
        $invoiceId = (string) ((int) ($invoice['id'] ?? 0));
        if ($invoiceId === '0') {
            return $this->handoffResult(
                $company,
                $conversation,
                'Não consegui identificar o boleto escolhido. Vou te encaminhar para um atendente.',
                $handoffArea
            );
        }

        if (! $sendOutbound) {
            return $this->returnToMainMenu(
                $company,
                "Boleto #{$invoiceId} selecionado. (Simulação sem envio ativo.)"
            );
        }

        try {
            $binaryPayload = $this->resolveInvoiceBinary($company, $clientId, $invoiceId);
            $filename = $this->resolveInvoiceFilename($invoiceId, (string) ($invoice['data_vencimento'] ?? ''));
            $caption = "Boleto {$invoiceId}";
            $upload = $this->whatsAppSend->uploadMedia(
                $company,
                (string) ($binaryPayload['binary'] ?? ''),
                (string) ($binaryPayload['content_type'] ?? 'application/pdf'),
                $filename
            );

            $mediaId = is_array($upload) ? trim((string) ($upload['id'] ?? '')) : '';
            if ($mediaId === '') {
                throw new RuntimeException('Falha no upload do boleto para o WhatsApp.');
            }

            $sendResult = $this->whatsAppSend->sendMedia(
                $company,
                (string) $conversation->customer_phone,
                $mediaId,
                'document',
                $caption
            );

            if (! (bool) ($sendResult['ok'] ?? false)) {
                $error = $this->extractSendError($sendResult);
                throw new RuntimeException($error !== '' ? $error : 'Falha ao enviar boleto pelo WhatsApp.');
            }
        } catch (RuntimeException) {
            return $this->handoffResult(
                $company,
                $conversation,
                'Não consegui enviar o boleto automaticamente. Vou te encaminhar para um atendente.',
                $handoffArea
            );
        }

        $result = $this->returnToMainMenu(
            $company,
            "Pronto! Enviei o boleto #{$invoiceId} no seu WhatsApp."
        );

        $result['extra_outbound_messages'] = [[
            'content_type' => 'document',
            'text' => $caption,
            'media_mime_type' => (string) ($binaryPayload['content_type'] ?? 'application/pdf'),
            'media_filename' => $filename,
            'meta' => [
                'source' => 'bot_ixc_invoice',
                'ixc_client_id' => (int) $clientId,
                'ixc_invoice_id' => (int) $invoiceId,
            ],
            'send_result' => is_array($sendResult ?? null) ? $sendResult : null,
        ]];

        return $result;
    }

    /**
     * @return array{clients: array<int, array{id:int,label:string}>, phone_mismatch: bool}
     */
    private function searchClientsByDocument(Company $company, string $document, string $senderPhone): array
    {
        $attempts = $this->buildDocumentLookupAttempts($document);

        foreach ($attempts as $attempt) {
            try {
                $payload = $this->ixcApi->request($company, (string) $attempt['resource'], (array) $attempt['params']);
                $list = $this->ixcApi->normalizeList($payload, 1, 20);
            } catch (RuntimeException) {
                continue;
            }

            $mapped = [];
            foreach ($list['items'] as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0 || isset($mapped[$id])) {
                    continue;
                }
                $name = trim((string) ($row['razao'] ?? $row['nome'] ?? $row['fantasia'] ?? "Cliente {$id}"));
                $doc = trim((string) ($row['cnpj_cpf'] ?? ''));
                $label = $name;
                if ($doc !== '') {
                    $label .= " - {$doc}";
                }
                $mapped[$id] = [
                    'id' => $id,
                    'label' => $label,
                    'phone_variants' => $this->extractClientPhoneVariants($row),
                ];
            }

            if ($mapped !== []) {
                $filterResult = $this->filterClientsBySenderPhone(array_values($mapped), $senderPhone);
                if ($filterResult['clients'] !== []) {
                    return [
                        'clients' => $filterResult['clients'],
                        'phone_mismatch' => false,
                    ];
                }

                if ($filterResult['strict_mismatch']) {
                    return [
                        'clients' => [],
                        'phone_mismatch' => true,
                    ];
                }

                return [
                    'clients' => [],
                    'phone_mismatch' => false,
                ];
            }
        }

        return [
            'clients' => [],
            'phone_mismatch' => false,
        ];
    }

    /**
     * @return array<int, array{resource:string,params:array<string,mixed>}>
     */
    private function buildDocumentLookupAttempts(string $document): array
    {
        $variants = $this->documentSearchVariants($document);
        $attempts = [];
        foreach ($variants as $variant) {
            $attempts[] = [
                'resource' => 'cliente',
                'params' => [
                    'qtype' => 'cliente.cnpj_cpf',
                    'query' => $variant,
                    'oper' => '=',
                    'page' => 1,
                    'rp' => 20,
                    'sortname' => 'cliente.id',
                    'sortorder' => 'asc',
                ],
            ];
        }

        $resourceAttempts = $this->buildAlternativeDocumentResourceAttempts($variants);
        foreach ($resourceAttempts as $resourceAttempt) {
            $attempts[] = $resourceAttempt;
        }

        $attempts[] = [
            'resource' => 'cliente',
            'params' => [
                'qtype' => 'cliente.cnpj_cpf',
                'query' => $document,
                'oper' => 'L',
                'page' => 1,
                'rp' => 20,
                'sortname' => 'cliente.id',
                'sortorder' => 'asc',
            ],
        ];

        return $attempts;
    }

    /**
     * @return array<int, string>
     */
    private function documentSearchVariants(string $document): array
    {
        $variants = [$document];

        $formatted = $this->formatCpfCnpjForSearch($document);
        if ($formatted !== '') {
            $variants[] = $formatted;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($value) => trim((string) $value),
            $variants
        ))));
    }

    private function formatCpfCnpjForSearch(string $document): string
    {
        if (strlen($document) === 11) {
            return preg_replace(
                '/(\d{3})(\d{3})(\d{3})(\d{2})/',
                '$1.$2.$3-$4',
                $document
            ) ?? '';
        }

        if (strlen($document) === 14) {
            return preg_replace(
                '/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/',
                '$1.$2.$3/$4-$5',
                $document
            ) ?? '';
        }

        return '';
    }

    /**
     * @param  array<int, string>  $variants
     * @return array<int, array{resource:string,params:array<string,mixed>}>
     */
    private function buildAlternativeDocumentResourceAttempts(array $variants): array
    {
        $resources = $this->resolveAlternativeClientResources();
        if ($resources === []) {
            return [];
        }

        $attempts = [];
        foreach ($resources as $resource) {
            if ($resource === 'listar_clientes_por_cpf') {
                foreach ($variants as $variant) {
                    foreach (['cpf', 'cpf_cnpj', 'cnpj_cpf'] as $field) {
                        $attempts[] = [
                            'resource' => $resource,
                            'params' => [
                                $field => $variant,
                                'page' => 1,
                                'rp' => 20,
                            ],
                        ];
                    }
                }
            }
        }

        return $attempts;
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
     * @param  array<int, array{id:int,label:string,phone_variants?:array<int,string>}>  $clients
     * @return array{clients: array<int, array{id:int,label:string}>, strict_mismatch: bool}
     */
    private function filterClientsBySenderPhone(array $clients, string $senderPhone): array
    {
        $senderVariants = PhoneNumberNormalizer::variantsForLookup($senderPhone);
        if ($senderVariants === []) {
            return [
                'clients' => array_map(
                    static fn (array $item): array => [
                        'id' => (int) ($item['id'] ?? 0),
                        'label' => (string) ($item['label'] ?? ''),
                    ],
                    $clients
                ),
                'strict_mismatch' => false,
            ];
        }

        $matched = [];
        $withoutPhone = [];
        $withPhoneNoMatch = [];

        foreach ($clients as $client) {
            $phoneVariants = is_array($client['phone_variants'] ?? null) ? $client['phone_variants'] : [];
            $normalizedClient = [
                'id' => (int) ($client['id'] ?? 0),
                'label' => (string) ($client['label'] ?? ''),
            ];

            if ($phoneVariants === []) {
                $withoutPhone[] = $normalizedClient;
                continue;
            }

            if ($this->hasIntersection($senderVariants, $phoneVariants)) {
                $matched[] = $normalizedClient;
            } else {
                $withPhoneNoMatch[] = $normalizedClient;
            }
        }

        if ($matched !== []) {
            return [
                'clients' => $matched,
                'strict_mismatch' => false,
            ];
        }

        if ($withPhoneNoMatch !== [] && $withoutPhone === []) {
            return [
                'clients' => [],
                'strict_mismatch' => true,
            ];
        }

        if ($withPhoneNoMatch !== [] && $withoutPhone !== []) {
            return [
                'clients' => [],
                'strict_mismatch' => true,
            ];
        }

        return [
            'clients' => $withoutPhone,
            'strict_mismatch' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function extractClientPhoneVariants(array $row): array
    {
        $possiblePhoneFields = [
            'whatsapp',
            'telefone_celular',
            'fone',
            'telefone_comercial',
            'telefone',
            'celular',
            'telefone_residencial',
            'fone_celular',
        ];

        $variants = [];
        foreach ($possiblePhoneFields as $field) {
            $raw = trim((string) ($row[$field] ?? ''));
            if ($raw === '') {
                continue;
            }

            foreach (PhoneNumberNormalizer::variantsForLookup($raw) as $variant) {
                if (! in_array($variant, $variants, true)) {
                    $variants[] = $variant;
                }
            }
        }

        return $variants;
    }

    /**
     * @param  array<int, string>  $left
     * @param  array<int, string>  $right
     */
    private function hasIntersection(array $left, array $right): bool
    {
        if ($left === [] || $right === []) {
            return false;
        }

        $rightMap = [];
        foreach ($right as $value) {
            $rightMap[(string) $value] = true;
        }

        foreach ($left as $value) {
            if (isset($rightMap[(string) $value])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{id:int,label:string,data_vencimento:string,valor:string}>
     */
    private function listOpenInvoices(Company $company, string $clientId): array
    {
        $gridFilters = [
            ['TB' => 'fn_areceber.liberado', 'OP' => '=', 'P' => 'S'],
            ['TB' => 'fn_areceber.status', 'OP' => '!=', 'P' => 'C'],
            ['TB' => 'fn_areceber.status', 'OP' => '!=', 'P' => 'R'],
        ];

        $params = [
            'qtype' => 'fn_areceber.id_cliente',
            'query' => $clientId,
            'oper' => '=',
            'page' => 1,
            'rp' => 30,
            'sortname' => 'fn_areceber.data_vencimento',
            'sortorder' => 'asc',
            'grid_param' => json_encode($gridFilters),
        ];

        $payload = $this->ixcApi->request($company, 'fn_areceber', $params);
        $list = $this->ixcApi->normalizeList($payload, 1, 30);

        $invoices = [];
        foreach ($list['items'] as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $dueDate = trim((string) ($row['data_vencimento'] ?? ''));
            $value = trim((string) ($row['valor'] ?? $row['valor_parcela'] ?? ''));
            $status = trim((string) ($row['status'] ?? ''));
            $summary = "Boleto {$id}";
            if ($dueDate !== '') {
                $summary .= " | Venc: {$dueDate}";
            }
            if ($value !== '') {
                $summary .= " | Valor: {$value}";
            }
            if ($status !== '') {
                $summary .= " | Status: {$status}";
            }

            $invoices[] = [
                'id' => $id,
                'label' => $summary,
                'data_vencimento' => $dueDate,
                'valor' => $value,
            ];
        }

        return $invoices;
    }

    /**
     * @return array{binary:string,content_type:string}
     */
    private function resolveInvoiceBinary(Company $company, string $clientId, string $invoiceId): array
    {
        $baseHost = strtolower((string) (parse_url((string) ($company->ixc_base_url ?? ''), PHP_URL_HOST) ?? ''));
        $allowPrivateHosts = (bool) config('ixc.allow_private_hosts', false);

        try {
            $jsonAttempt = $this->ixcApi->request($company, 'get_boleto', [
                'id' => $invoiceId,
                'id_cliente' => $clientId,
            ]);
            $payloadBinary = $this->extractBinaryFromPayload($company, $jsonAttempt, $baseHost, $allowPrivateHosts);
            if ($payloadBinary !== null) {
                return $payloadBinary;
            }
        } catch (RuntimeException) {
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
                        $payloadBinary = $this->extractBinaryFromPayload($company, $decoded, $baseHost, $allowPrivateHosts);
                        if ($payloadBinary !== null) {
                            return $payloadBinary;
                        }
                    }
                } else {
                    return [
                        'binary' => $body,
                        'content_type' => (string) ($binaryAttempt['content_type'] ?? 'application/pdf'),
                    ];
                }
            }
        } catch (RuntimeException) {
        }

        $invoiceRow = $this->loadInvoiceRow($company, $clientId, $invoiceId);
        if (is_array($invoiceRow)) {
            $payloadBinary = $this->extractBinaryFromPayload($company, $invoiceRow, $baseHost, $allowPrivateHosts);
            if ($payloadBinary !== null) {
                return $payloadBinary;
            }
        }

        throw new RuntimeException('Não foi possível obter o arquivo do boleto na IXC.');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{binary:string,content_type:string}|null
     */
    private function extractBinaryFromPayload(
        Company $company,
        array $payload,
        string $baseHost,
        bool $allowPrivateHosts
    ): ?array {
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
     * @return array<string, mixed>|null
     */
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
                ['TB' => 'fn_areceber.id_cliente', 'OP' => '=', 'P' => $clientId],
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

    private function resolveHandoffArea(array $context): string
    {
        $area = trim((string) ($context['handoff_area'] ?? BotFlowRegistry::AREA_ATTENDANCE));

        return $area !== '' ? $area : BotFlowRegistry::AREA_ATTENDANCE;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function invalidAttempt(
        Company $company,
        Conversation $conversation,
        string $step,
        array $context,
        string $message,
        string $handoffArea
    ): array {
        $attempts = max(0, (int) ($context['attempts'] ?? 0)) + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            return $this->handoffResult(
                $company,
                $conversation,
                'Não consegui concluir a consulta de boletos. Vou te encaminhar para um atendente.',
                $handoffArea
            );
        }

        $state = [
            'flow' => BotFlow::IXC_INVOICES->value,
            'step' => $step,
            'context' => array_merge($context, [
                'attempts' => $attempts,
                'handoff_area' => $handoffArea,
            ]),
        ];

        return $this->botStateResult($message, $state);
    }

    /**
     * @param  array<string, array{id:int,label:string}>  $options
     * @return array<string, mixed>
     */
    private function buildChoiceMessage(string $bodyText, array $options, string $actionLabel): array
    {
        if (count($options) <= 3) {
            $buttons = [];
            foreach ($options as $key => $option) {
                $buttons[] = [
                    'id' => (string) $key,
                    'title' => "Opção {$key}",
                ];
            }

            return [
                'type' => 'interactive_buttons',
                'body_text' => $bodyText,
                'header_text' => '',
                'footer_text' => '',
                'buttons' => $buttons,
            ];
        }

        $rows = [];
        foreach ($options as $key => $option) {
            $rows[] = [
                'id' => (string) $key,
                'title' => "Opção {$key}",
                'description' => mb_substr((string) ($option['label'] ?? ''), 0, 60),
            ];
        }

        return [
            'type' => 'interactive_list',
            'body_text' => $bodyText,
            'header_text' => '',
            'footer_text' => '',
            'action_label' => $actionLabel,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConfirmMessage(string $bodyText): array
    {
        return [
            'type' => 'interactive_buttons',
            'body_text' => $bodyText,
            'header_text' => '',
            'footer_text' => '',
            'buttons' => [
                ['id' => '1', 'title' => 'Confirmar'],
                ['id' => '2', 'title' => 'Cancelar'],
            ],
        ];
    }

    /**
     * @param  array<string, array{id:int,label:string}>  $options
     */
    private function resolveSelectionKey(string $input, array $options): ?string
    {
        $normalized = trim((string) $input);
        if ($normalized === '') {
            return null;
        }

        foreach ($options as $key => $option) {
            if ((string) $key === $normalized) {
                return (string) $key;
            }
        }

        return null;
    }

    private function isConfirmInput(string $input): bool
    {
        $token = $this->normalizeDecisionToken($input);
        if ($token === '') {
            return false;
        }

        return in_array($token, ['1', 'sim', 's', 'confirmar', 'enviar', 'ok'], true);
    }

    private function isCancelInput(string $input): bool
    {
        $token = $this->normalizeDecisionToken($input);
        if ($token === '') {
            return false;
        }

        return in_array($token, ['2', 'nao', 'n', 'cancelar', 'voltar'], true);
    }

    private function normalizeDecisionToken(string $value): string
    {
        $accents = [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ç' => 'c',
        ];

        $normalized = mb_strtolower(trim((string) strtr($value, $accents)));
        return preg_replace('/\s+/', ' ', $normalized) ?? '';
    }

    private function returnToMainMenu(Company $company, string $message): array
    {
        $definition = $this->registry->definitionForCompany($company);
        $menu = $this->buildInitialMenuResponse($definition);

        $menuText = trim((string) ($menu['reply_text'] ?? ''));
        $replyText = trim($message);
        if ($menuText !== '') {
            $replyText = $replyText !== '' ? "{$replyText}\n\n{$menuText}" : $menuText;
        }

        $newState = is_array($menu['new_state'] ?? null) ? $menu['new_state'] : [
            'flow' => BotFlow::MAIN->value,
            'step' => BotFlowRegistry::STEP_MENU,
            'context' => ['last_menu_keys' => ['1', '2', '3']],
        ];

        return $this->botStateResult($replyText, $newState);
    }

    private function resolveInvoiceFilename(string $invoiceId, string $dueDate): string
    {
        $normalizedDate = preg_replace('/[^0-9]/', '', $dueDate) ?: date('Ymd');
        return "boleto_{$invoiceId}_{$normalizedDate}.pdf";
    }

    /**
     * @param  array<string, mixed>  $sendResult
     */
    private function extractSendError(array $sendResult): string
    {
        $error = $sendResult['error'] ?? null;
        if (is_string($error)) {
            return trim($error);
        }

        if (is_array($error)) {
            $message = trim((string) (Arr::get($error, 'message') ?? Arr::get($error, 'error_user_msg') ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return '';
    }
}
