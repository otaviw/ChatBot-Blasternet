import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import CompanyInboxPage from './CompanyInboxPage.jsx';

const mockUsePageData = vi.fn();
const mockUseLogout = vi.fn();
const mockUseNotificationsContext = vi.fn();
const mockConversationsHook = vi.fn();
const mockDetailHook = vi.fn();
const mockActionsHook = vi.fn();

vi.mock('@/hooks/usePageData', () => ({ default: (...args) => mockUsePageData(...args) }));
vi.mock('@/components/layout/Layout/Layout.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/hooks/useLogout', () => ({ default: () => mockUseLogout() }));
vi.mock('@/hooks/useNotificationsContext', () => ({ useNotificationsContext: () => mockUseNotificationsContext() }));
vi.mock('./hooks/useCompanyInboxConversations', () => ({ default: (...args) => mockConversationsHook(...args) }));
vi.mock('./hooks/useCompanyInboxDetailMessages', () => ({ default: (...args) => mockDetailHook(...args) }));
vi.mock('./hooks/useCompanyInboxActions', () => ({ default: (...args) => mockActionsHook(...args) }));
vi.mock('./useInboxRealtimeSync', () => ({ default: vi.fn() }));
vi.mock('@/services/api', () => ({ default: { get: vi.fn() } }));

vi.mock('./components/ConversationsSidebar.jsx', () => ({
  default: ({ onNewConversation }) => (
    <button type="button" onClick={onNewConversation}>Abrir nova conversa</button>
  ),
}));
vi.mock('./components/ConversationToolbar.jsx', () => ({ default: () => <div>toolbar</div> }));
vi.mock('./components/MessagesPanel.jsx', () => ({ default: () => <div>messages</div> }));
vi.mock('./components/ReplyComposer.jsx', () => ({ default: () => <div>composer</div> }));
vi.mock('./components/TagsModal.jsx', () => ({ default: () => null }));
vi.mock('./components/TransferModal.jsx', () => ({ default: () => null }));
vi.mock('./components/SendTemplateModal.jsx', () => ({ default: () => null }));
vi.mock('./components/ConversationSearchModal.jsx', () => ({ default: () => null }));
vi.mock('./components/NewConversationModal.jsx', () => ({
  default: ({ open }) => (open ? <div>modal nova conversa aberta</div> : null),
}));

function mount(ui) {
  const container = document.createElement('div');
  document.body.appendChild(container);
  const root = createRoot(container);
  act(() => root.render(ui));
  return { container, root };
}

function setupBaseMocks() {
  mockUsePageData.mockImplementation((path) => {
    if (path.startsWith('/minha-conta/conversas')) {
      return { data: { authenticated: true, attendants: [], company_tags: [] }, loading: false, error: null };
    }
    if (path === '/areas') return { data: { areas: [] }, loading: false, error: null };
    return { data: { authenticated: true }, loading: false, error: null };
  });
  mockUseLogout.mockReturnValue({ logout: vi.fn() });
  mockUseNotificationsContext.mockReturnValue({
    markReadByReference: vi.fn(),
    unreadConversationIds: [],
    setActiveConversationId: vi.fn(),
  });
  mockConversationsHook.mockReturnValue({
    conversationListRef: { current: null },
    conversations: [],
    conversationsLoading: false,
    conversationsLoadingMore: false,
    conversationsPagination: {},
    convSearchInput: '',
    filters: {},
    hasMoreSearchResults: false,
    isSearchMode: false,
    searchTerm: '',
    searchResultsTotal: 0,
    handleConversationsScroll: vi.fn(),
    handleConversationsSearchEnter: vi.fn(),
    handleNextConversationPage: vi.fn(),
    loadMoreSearchResults: vi.fn(),
    loadedConversationPageRef: { current: 1 },
    refreshConversations: vi.fn(),
    setConversations: vi.fn(),
    setConvSearchInput: vi.fn(),
    setFilters: vi.fn(),
    upsertConversationInList: vi.fn(),
  });
  mockDetailHook.mockReturnValue({
    chatListRef: { current: null },
    clearConversationPresence: vi.fn(),
    contactNameInput: '',
    detail: null,
    detailError: '',
    detailLoading: false,
    handleChatScroll: vi.fn(),
    loadMessagesPage: vi.fn(),
    messagesLoadingOlder: false,
    messagesPagination: {},
    openConversation: vi.fn(),
    openConversationAtMessagePage: vi.fn().mockResolvedValue(true),
    refreshConversationDetail: vi.fn(),
    selectedId: null,
    selectedIdRef: { current: null },
    setContactNameInput: vi.fn(),
    setDetail: vi.fn(),
    setDetailError: vi.fn(),
    setSelectedId: vi.fn(),
    shouldScrollChatToBottomRef: { current: false },
    transferOptions: { areas: [] },
    wasChatNearBottomRef: { current: true },
  });
  mockActionsHook.mockReturnValue({
    actionBusy: false,
    attachTag: vi.fn(),
    deleteConversation: vi.fn(),
    deleteBusy: false,
    deleteError: '',
    detachTag: vi.fn(),
    aiSuggestionBusy: false,
    aiSuggestionError: '',
    aiSuggestionStatus: '',
    aiConfidenceScore: null,
    aiSuggestionFeedbackState: '',
    submitAiSuggestionFeedback: vi.fn(),
    assumeConversation: vi.fn(),
    availableUsers: [],
    closeConversation: vi.fn(),
    contactBusy: false,
    contactError: '',
    contactSuccess: '',
    getMessageImageUrl: vi.fn(),
    handleApplyQuickReply: vi.fn(),
    handleContactNameInputChange: vi.fn(),
    handleManualImageChange: vi.fn(),
    handleTransferAreaChange: vi.fn(),
    handleTransferUserChange: vi.fn(),
    manualBusy: false,
    manualError: '',
    manualImageFile: null,
    manualImagePreviewUrl: '',
    manualText: '',
    quickReplies: [],
    requestAiSuggestion: vi.fn(),
    releaseConversation: vi.fn(),
    removeManualImage: vi.fn(),
    resetForOpenConversation: vi.fn(),
    saveContactName: vi.fn(),
    sendManualReply: vi.fn(),
    setManualText: vi.fn(),
    setShowTemplates: vi.fn(),
    setTagsModalOpen: vi.fn(),
    setTransferModalOpen: vi.fn(),
    showTemplates: false,
    tagsModalOpen: false,
    transferArea: '',
    transferBusy: false,
    transferError: '',
    transferModalOpen: false,
    transferSuccess: '',
    transferUserId: '',
    transferConversation: vi.fn(),
    createConversation: vi.fn().mockResolvedValue({ id: 1 }),
    newConvModalOpen: false,
    newConvBusy: false,
    newConvError: '',
    setNewConvModalOpen: vi.fn(),
    sendTemplateToConversation: vi.fn(),
    sendTemplateModalOpen: false,
    sendTemplateBusy: false,
    sendTemplateError: '',
    sendTemplateSuccess: '',
    setSendTemplateModalOpen: vi.fn(),
    usageWarning: '',
    setUsageWarning: vi.fn(),
  });
}

describe('CompanyInboxPage', () => {
  let mounted;

  beforeEach(() => {
    globalThis.IS_REACT_ACT_ENVIRONMENT = true;
  });

  afterEach(() => {
    mockUsePageData.mockReset();
    mockUseLogout.mockReset();
    mockUseNotificationsContext.mockReset();
    mockConversationsHook.mockReset();
    mockDetailHook.mockReset();
    mockActionsHook.mockReset();
    if (mounted) {
      act(() => mounted.root.unmount());
      mounted.container.remove();
      mounted = null;
    }
  });

  it('abre modal de nova conversa via ação da sidebar', async () => {
    setupBaseMocks();
    const setNewConvModalOpen = vi.fn();
    mockActionsHook.mockReturnValue({
      actionBusy: false,
      attachTag: vi.fn(),
      deleteConversation: vi.fn(),
      deleteBusy: false,
      deleteError: '',
      detachTag: vi.fn(),
      aiSuggestionBusy: false,
      aiSuggestionError: '',
      aiSuggestionStatus: '',
      aiConfidenceScore: null,
      aiSuggestionFeedbackState: '',
      submitAiSuggestionFeedback: vi.fn(),
      assumeConversation: vi.fn(),
      availableUsers: [],
      closeConversation: vi.fn(),
      contactBusy: false,
      contactError: '',
      contactSuccess: '',
      getMessageImageUrl: vi.fn(),
      handleApplyQuickReply: vi.fn(),
      handleContactNameInputChange: vi.fn(),
      handleManualImageChange: vi.fn(),
      handleTransferAreaChange: vi.fn(),
      handleTransferUserChange: vi.fn(),
      manualBusy: false,
      manualError: '',
      manualImageFile: null,
      manualImagePreviewUrl: '',
      manualText: '',
      quickReplies: [],
      requestAiSuggestion: vi.fn(),
      releaseConversation: vi.fn(),
      removeManualImage: vi.fn(),
      resetForOpenConversation: vi.fn(),
      saveContactName: vi.fn(),
      sendManualReply: vi.fn(),
      setManualText: vi.fn(),
      setShowTemplates: vi.fn(),
      setTagsModalOpen: vi.fn(),
      setTransferModalOpen: vi.fn(),
      showTemplates: false,
      tagsModalOpen: false,
      transferArea: '',
      transferBusy: false,
      transferError: '',
      transferModalOpen: false,
      transferSuccess: '',
      transferUserId: '',
      transferConversation: vi.fn(),
      createConversation: vi.fn().mockResolvedValue({ id: 1 }),
      newConvModalOpen: false,
      newConvBusy: false,
      newConvError: '',
      setNewConvModalOpen,
      sendTemplateToConversation: vi.fn(),
      sendTemplateModalOpen: false,
      sendTemplateBusy: false,
      sendTemplateError: '',
      sendTemplateSuccess: '',
      setSendTemplateModalOpen: vi.fn(),
      usageWarning: '',
      setUsageWarning: vi.fn(),
    });

    mounted = mount(<CompanyInboxPage />);

    const trigger = [...mounted.container.querySelectorAll('button')]
      .find((btn) => btn.textContent.includes('Abrir nova conversa'));
    expect(trigger).toBeTruthy();

    await act(async () => trigger.click());
    expect(setNewConvModalOpen).toHaveBeenCalledWith(true);
  });
});
