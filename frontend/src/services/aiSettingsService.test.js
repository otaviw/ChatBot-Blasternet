import { describe, expect, it, vi } from 'vitest';
import { normalizeAiSettings } from './aiSettingsService';

vi.mock('@/services/apiClient', () => ({
  get: vi.fn(),
  put: vi.fn(),
}));

describe('aiSettingsService', () => {
  describe('normalizeAiSettings', () => {
    it('normaliza campos do wizard de IA mantendo tipos esperados pelo backend', () => {
      const settings = normalizeAiSettings({
        ai_enabled: true,
        ai_chatbot_enabled: true,
        ai_chatbot_shadow_mode: true,
        ai_chatbot_sandbox_enabled: true,
        ai_chatbot_auto_reply_enabled: true,
        ai_chatbot_mode: 'fallback',
        ai_chatbot_test_numbers: '5511999999999\n5521888888888',
        ai_chatbot_rules: ['  Nao inventar servicos  ', '', null, 'Respeitar menu'],
        ai_chatbot_confidence_threshold: '0.82',
        ai_chatbot_handoff_repeat_limit: '3',
        ai_max_context_messages: '15',
        ai_temperature: '0.4',
        ai_max_response_tokens: '900',
        ai_provider: 'openai',
        ai_model: 'gpt-test',
      });

      expect(settings).toMatchObject({
        ai_enabled: true,
        ai_chatbot_enabled: true,
        ai_chatbot_shadow_mode: true,
        ai_chatbot_sandbox_enabled: true,
        ai_chatbot_auto_reply_enabled: true,
        ai_chatbot_mode: 'fallback',
        ai_chatbot_test_numbers: ['5511999999999', '5521888888888'],
        ai_chatbot_rules: ['Nao inventar servicos', 'Respeitar menu'],
        ai_chatbot_confidence_threshold: 0.82,
        ai_chatbot_handoff_repeat_limit: 3,
        ai_max_context_messages: 15,
        ai_temperature: 0.4,
        ai_max_response_tokens: 900,
        ai_provider: 'openai',
        ai_model: 'gpt-test',
      });
    });

    it('usa defaults seguros quando valores opcionais chegam vazios ou invalidos', () => {
      const settings = normalizeAiSettings({
        ai_chatbot_confidence_threshold: 'abc',
        ai_chatbot_handoff_repeat_limit: '',
        ai_chatbot_test_numbers: null,
        ai_chatbot_rules: null,
        ai_max_context_messages: '',
        ai_temperature: '',
        ai_max_response_tokens: '',
      });

      expect(settings.ai_chatbot_confidence_threshold).toBe(0.75);
      expect(settings.ai_chatbot_handoff_repeat_limit).toBe(2);
      expect(settings.ai_chatbot_test_numbers).toEqual([]);
      expect(settings.ai_chatbot_rules).toEqual([]);
      expect(settings.ai_max_context_messages).toBeNull();
      expect(settings.ai_temperature).toBeNull();
      expect(settings.ai_max_response_tokens).toBeNull();
    });
  });
});
