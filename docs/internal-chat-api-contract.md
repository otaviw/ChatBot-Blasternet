# Internal Chat API Contract (Canonical + Temporary Compatibility)

## Scope
This document defines the canonical API contract for Internal Chat used by `frontend/src/services/internalChatService.js`, plus the temporary compatibility matrix for legacy aliases.

## Contract Version
- Version: `v1-canonical`
- Frontend compatibility flag: `VITE_INTERNAL_CHAT_ENABLE_LEGACY_ENDPOINTS`
- Default behavior: `enabled` (`1`) to keep production safe during migration.

## Role Prefix Rules
Canonical route templates are expanded by role prefixes in this priority order:

- `company`: `/minha-conta`, ``, `/admin`
- `admin`: `/admin`, ``, `/minha-conta`

Example for canonical `GET /chat/conversations`:
- company order: `GET /minha-conta/chat/conversations`, `GET /chat/conversations`, `GET /admin/chat/conversations`
- admin order: `GET /admin/chat/conversations`, `GET /chat/conversations`, `GET /minha-conta/chat/conversations`

## Official Endpoints (1 per action)
1. `GET /chat/conversations`
2. `GET /chat/conversations/:conversationId`
3. `POST /chat/conversations`
4. `POST /chat/conversations/:conversationId/messages`
5. `PATCH /chat/conversations/:conversationId/messages/:messageId`
6. `DELETE /chat/conversations/:conversationId/messages/:messageId`
7. `POST /chat/conversations/:conversationId/read`
8. `GET /chat/users`

## Request/Response Shapes
The service accepts tolerant payloads (snake_case/camelCase aliases), but these are the canonical shapes to adopt in backend responses.

### 1) List conversations
- Request:
  - Query: `search` (optional string)
- Response:
```json
{
  "conversations": [
    {
      "id": 101,
      "type": "direct",
      "unread_count": 2,
      "participants": [{ "id": 1, "name": "Ana" }],
      "last_message": { "id": 501, "content": "Oi" },
      "last_message_at": "2026-03-10T12:00:00Z"
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 25,
    "total": 1
  }
}
```

### 2) Get conversation detail
- Request:
  - Path: `conversationId` (positive integer)
- Response:
```json
{
  "conversation": {
    "id": 101,
    "participants": [{ "id": 1, "name": "Ana" }],
    "messages": [
      {
        "id": 501,
        "conversation_id": 101,
        "sender_id": 1,
        "sender_name": "Ana",
        "type": "text",
        "content": "Oi",
        "attachments": [],
        "created_at": "2026-03-10T12:00:00Z",
        "updated_at": "2026-03-10T12:00:00Z",
        "edited_at": null,
        "deleted_at": null,
        "is_deleted": false
      }
    ]
  }
}
```

### 3) Create direct conversation
- Request:
```json
{
  "type": "direct",
  "recipient_id": 12,
  "content": "Mensagem inicial opcional"
}
```
- Response:
```json
{
  "conversation": { "id": 102, "type": "direct", "participants": [] },
  "message": { "id": 502, "conversation_id": 102, "content": "Mensagem inicial opcional" }
}
```

### 4) Send message
- Request (text):
```json
{
  "conversation_id": 102,
  "type": "text",
  "content": "Texto da mensagem"
}
```
- Request (file): `multipart/form-data`
  - `conversation_id`
  - `type` (`image` or `file`)
  - `file`
  - optional `content`
- Response:
```json
{
  "message": { "id": 503, "conversation_id": 102, "content": "Texto" },
  "conversation": { "id": 102, "last_message_at": "2026-03-10T12:05:00Z" }
}
```

### 5) Edit message
- Request:
```json
{
  "conversation_id": 102,
  "message_id": 503,
  "content": "Texto editado"
}
```
- Response:
```json
{
  "message": { "id": 503, "conversation_id": 102, "content": "Texto editado" },
  "conversation": { "id": 102 }
}
```

### 6) Delete message
- Request body:
```json
{
  "conversation_id": 102,
  "message_id": 503
}
```
- Response:
```json
{
  "message": { "id": 503, "conversation_id": 102, "is_deleted": true },
  "conversation": { "id": 102 }
}
```

### 7) Mark conversation as read
- Request:
  - Path: `conversationId`
  - Body optional (can be empty)
- Response:
```json
{
  "success": true
}
```

### 8) List recipients
- Request: no params
- Response:
```json
{
  "users": [
    {
      "id": 12,
      "name": "Joao",
      "email": "joao@empresa.com",
      "role": "company_user",
      "is_active": true
    }
  ]
}
```

## Compatibility Matrix
When `VITE_INTERNAL_CHAT_ENABLE_LEGACY_ENDPOINTS=1`, the frontend tries canonical first, then deprecated aliases.

| Action | Canonical | Deprecated aliases (temporary) |
|---|---|---|
| listConversations | `/chat/conversations` | `/chat/conversas` |
| getConversation | `/chat/conversations/:conversationId` | `/chat/conversas/:conversationId` |
| createConversation | `/chat/conversations` | `/chat/conversas` |
| sendMessage | `/chat/conversations/:conversationId/messages` | `/chat/conversas/:conversationId/mensagens`, `/chat/messages`, `/chat/mensagens` |
| updateMessage | `/chat/conversations/:conversationId/messages/:messageId` | `/chat/conversas/:conversationId/mensagens/:messageId`, `/chat/messages/:messageId`, `/chat/mensagens/:messageId` |
| deleteMessage | `/chat/conversations/:conversationId/messages/:messageId` | `/chat/conversas/:conversationId/mensagens/:messageId`, `/chat/messages/:messageId`, `/chat/mensagens/:messageId` |
| markRead | `/chat/conversations/:conversationId/read` | `/chat/conversas/:conversationId/lido`, `/chat/conversations/:conversationId/mark-read`, `/chat/conversas/:conversationId/marcar-lido` |
| listRecipients | `/chat/users` | `/chat/usuarios`, `/chat/recipients`, `/chat/destinatarios`, role fallbacks `/admin/users`, `/minha-conta/users` |

## Safe Migration Plan (Phased)
1. Phase A (current): Keep `VITE_INTERNAL_CHAT_ENABLE_LEGACY_ENDPOINTS=1` in production. Backend should expose canonical routes and still answer aliases.
2. Phase B: Monitor logs/metrics for alias usage. Track calls that still hit deprecated routes.
3. Phase C: In staging, set `VITE_INTERNAL_CHAT_ENABLE_LEGACY_ENDPOINTS=0` and validate all chat flows.
4. Phase D: Enable `VITE_INTERNAL_CHAT_ENABLE_LEGACY_ENDPOINTS=0` in production after alias usage reaches zero.
5. Phase E: Remove deprecated aliases from frontend service and backend.

## Notes for Backend + Frontend Teams
- Backend should prioritize canonical response keys (`conversation`, `conversations`, `message`, `messages`, `users`, `pagination`) even while returning compatibility aliases.
- Frontend remains tolerant during migration, but canonical contract above is the single source of truth for new implementations.
