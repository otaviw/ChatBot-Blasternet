<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\InboundMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimulatedMessageController extends Controller
{
    public function __construct(
        private InboundMessageService $inboundMessage,
        private AuditLogService $auditLog
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isSystemAdmin() && ! $user->isCompanyUser())) {
            return response()->json([
                'authenticated' => false,
                'redirect' => '/entrar',
            ], 403);
        }
        $role = User::normalizeRole($user->role);
        $canViewMedia = $user->isCompanyUser();

        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'from' => ['required', 'string', 'max:40'],
            'text' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'file', 'image', 'max:'.config('whatsapp.media_max_size_kb', 5120)],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'send_outbound' => ['sometimes', 'boolean'],
        ]);

        $companyId = (int) $validated['company_id'];
        if ($user->isCompanyUser() && (int) $user->company_id !== $companyId) {
            return response()->json([
                'authenticated' => true,
                'message' => 'Empresa nao pode simular mensagens para outro tenant.',
            ], 403);
        }

        $company = Company::with('botSetting')->findOrFail($companyId);
        $sendOutbound = (bool) ($validated['send_outbound'] ?? true);
        $text = trim((string) ($validated['text'] ?? ''));
        $image = $request->file('image');
        if ($text === '' && ! $image) {
            return response()->json([
                'message' => 'Informe texto ou imagem para simular.',
            ], 422);
        }

        if ($image) {
            $result = $this->inboundMessage->handleIncomingUploadedImage(
                $company,
                (string) $validated['from'],
                $image,
                $text !== '' ? $text : null,
                [
                    'source' => 'simulation',
                    'triggered_by_role' => $role,
                    'incoming_type' => 'image',
                ],
                $validated['contact_name'] ?? null
            );
        } else {
            $result = $this->inboundMessage->handleIncomingText(
                $company,
                (string) $validated['from'],
                $text,
                [
                    'source' => 'simulation',
                    'triggered_by_role' => $role,
                ],
                [
                    'source' => 'simulation',
                ],
                $sendOutbound,
                $validated['contact_name'] ?? null
            );
        }

        $this->auditLog->record(
            $request,
            'bot.simulation.executed',
            $company->id,
            [
                'conversation_id' => $result['conversation']->id,
                'outbound_sent' => $result['was_sent'],
            ],
            [
                'from' => substr((string) $validated['from'], 0, 6) . '***',
                'text_length' => mb_strlen($text),
                'has_image' => (bool) $image,
            ]
        );

        return response()->json([
            'ok' => true,
            'company_id' => $company->id,
            'conversation' => [
                'id' => $result['conversation']->id,
                'customer_phone' => $result['conversation']->customer_phone,
                'customer_name' => $result['conversation']->customer_name,
                'status' => $result['conversation']->status,
            ],
            'in_message' => [
                'id' => $result['in_message']->id,
                'text' => $result['in_message']->text,
                'content_type' => $result['in_message']->content_type,
                'media_url' => $canViewMedia ? $result['in_message']->media_url : null,
            ],
            'out_message' => [
                'id' => $result['out_message']?->id,
                'text' => $result['out_message']?->text,
            ],
            'reply' => $result['reply'],
            'send_outbound' => $sendOutbound,
            'was_sent' => $result['was_sent'],
            'auto_replied' => $result['auto_replied'] ?? true,
        ]);
    }
}
