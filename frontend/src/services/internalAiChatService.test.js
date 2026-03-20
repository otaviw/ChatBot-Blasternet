import { beforeEach, describe, expect, it, vi } from 'vitest';
import api from '@/services/api';
import {
  createInternalAiConversation,
  getInternalAiConversation,
  listInternalAiConversations,
  sendInternalAiConversationMessage,
} from './internalAiChatService';

vi.mock('@/services/api', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
  },
}));

describe('internalAiChatService', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe('listInternalAiConversations', () => {
    it('carrega conversas e normaliza paginação', async () => {
      api.get.mockResolvedValue({
        data: {
          conversations: [
            {
              id: 2,
              title: 'Conversa B',
              last_message_at: '2026-03-20T09:00:00.000Z',
            },
            {
              id: 1,
              title: 'Conversa A',
              last_message_at: '2026-03-20T10:00:00.000Z',
            },
          ],
          conversations_pagination: {
            current_page: '1',
            last_page: '2',
            per_page: '15',
            total: '18',
          },
        },
      });

      const response = await listInternalAiConversations({
        search: '  financeiro ',
        page: '2',
        perPage: 200,
      });

      expect(api.get).toHaveBeenCalledWith('/minha-conta/ia/conversas', {
        params: {
          search: 'financeiro',
          page: 2,
          per_page: 50,
        },
      });
      expect(response.conversations.map((conversation) => conversation.id)).toEqual([1, 2]);
      expect(response.pagination).toEqual({
        current_page: 1,
        last_page: 2,
        per_page: 15,
        total: 18,
      });
    });

    it('retorna lista vazia quando API nao trouxer conversas', async () => {
      api.get.mockResolvedValue({ data: {} });

      const response = await listInternalAiConversations();

      expect(response.conversations).toEqual([]);
      expect(response.pagination).toBeNull();
    });
  });

  describe('createInternalAiConversation', () => {
    it('cria conversa usando titulo normalizado', async () => {
      api.post.mockResolvedValue({
        data: {
          conversation: {
            id: 15,
            title: 'Conversa comercial',
            origin: 'internal_chat',
          },
        },
      });

      const response = await createInternalAiConversation({ title: '  Conversa comercial ' });

      expect(api.post).toHaveBeenCalledWith('/minha-conta/ia/conversas', {
        title: 'Conversa comercial',
      });
      expect(response.conversation?.id).toBe(15);
      expect(response.conversation?.title).toBe('Conversa comercial');
    });
  });

  describe('getInternalAiConversation', () => {
    it('abre conversa e ordena mensagens cronologicamente', async () => {
      api.get.mockResolvedValue({
        data: {
          conversation: {
            id: 9,
            title: 'Conversa suporte',
            messages: [
              {
                id: 2,
                role: 'assistant',
                content: 'Resposta',
                created_at: '2026-03-20T10:05:00.000Z',
              },
              {
                id: 1,
                role: 'user',
                content: 'Pergunta',
                created_at: '2026-03-20T10:00:00.000Z',
              },
            ],
          },
          messages_pagination: {
            current_page: '3',
            last_page: '5',
            per_page: '30',
            total: '120',
          },
        },
      });

      const response = await getInternalAiConversation({
        conversationId: '9',
        messagesPage: '2',
        messagesPerPage: 999,
      });

      expect(api.get).toHaveBeenCalledWith('/minha-conta/ia/conversas/9', {
        params: {
          messages_page: 2,
          messages_per_page: 100,
        },
      });
      expect(response.conversation?.messages.map((message) => message.id)).toEqual([1, 2]);
      expect(response.messagesPagination).toEqual({
        current_page: 3,
        last_page: 5,
        per_page: 30,
        total: 120,
      });
    });

    it('falha quando conversationId for invalido', async () => {
      await expect(
        getInternalAiConversation({
          conversationId: null,
        }),
      ).rejects.toThrow('Conversa invalida.');
    });
  });

  describe('sendInternalAiConversationMessage', () => {
    it('envia mensagem e retorna resposta da API normalizada', async () => {
      api.post.mockResolvedValue({
        data: {
          conversation: {
            id: 19,
            title: 'Conversa RH',
          },
          user_message: {
            id: 100,
            role: 'user',
            content: 'Como funciona o ponto?',
            created_at: '2026-03-20T11:00:00.000Z',
          },
          assistant_message: {
            id: 101,
            role: 'assistant',
            content: 'O registro de ponto fica no menu de colaboradores.',
            created_at: '2026-03-20T11:00:01.000Z',
          },
        },
      });

      const response = await sendInternalAiConversationMessage({
        conversationId: 19,
        content: '  Como funciona o ponto?  ',
      });

      expect(api.post).toHaveBeenCalledWith('/minha-conta/ia/conversas/19/mensagens', {
        content: 'Como funciona o ponto?',
        text: 'Como funciona o ponto?',
      });
      expect(response.userMessage?.id).toBe(100);
      expect(response.assistantMessage?.id).toBe(101);
      expect(response.assistantMessage?.content).toBe(
        'O registro de ponto fica no menu de colaboradores.',
      );
      expect(response.conversation?.last_message?.id).toBe(101);
      expect(response.conversation?.last_message_at).toBe('2026-03-20T11:00:01.000Z');
    });

    it('falha quando content estiver vazio', async () => {
      await expect(
        sendInternalAiConversationMessage({
          conversationId: 1,
          content: '   ',
        }),
      ).rejects.toThrow('Informe uma mensagem para continuar.');
    });
  });
});
