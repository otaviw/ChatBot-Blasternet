import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ContactsPage from './ContactsPage.jsx';

const mockUsePageData = vi.fn();
const mockUseLogout = vi.fn();
const mockUseContacts = vi.fn();
const mockShowSuccess = vi.fn();
const mockShowError = vi.fn();
const mockCreateContact = vi.fn();

vi.mock('@/hooks/usePageData', () => ({ default: (...args) => mockUsePageData(...args) }));
vi.mock('@/components/layout/Layout/Layout.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('@/hooks/useLogout', () => ({ default: () => mockUseLogout() }));
vi.mock('./hooks/useContacts', () => ({ default: () => mockUseContacts() }));
vi.mock('@/services/toastService', () => ({ showSuccess: (...args) => mockShowSuccess(...args), showError: (...args) => mockShowError(...args) }));
vi.mock('./components/ContactDetailModal.jsx', () => ({ default: () => null }));

function mount(ui) {
  const container = document.createElement('div');
  document.body.appendChild(container);
  const root = createRoot(container);
  act(() => root.render(ui));
  return { container, root };
}

function baseContactsMock() {
  return {
    contacts: [],
    searchQuery: '',
    loading: false,
    error: '',
    creating: false,
    importing: false,
    saving: false,
    deleting: false,
    searchContacts: vi.fn(),
    refetch: vi.fn(),
    createContact: mockCreateContact,
    updateContact: vi.fn(),
    deleteContact: vi.fn(),
    importCsv: vi.fn(),
  };
}

describe('ContactsPage', () => {
  let mounted;

  beforeEach(() => {
    globalThis.IS_REACT_ACT_ENVIRONMENT = true;
  });

  afterEach(() => {
    mockUsePageData.mockReset();
    mockUseLogout.mockReset();
    mockUseContacts.mockReset();
    mockShowSuccess.mockReset();
    mockShowError.mockReset();
    mockCreateContact.mockReset();

    if (mounted) {
      act(() => mounted.root.unmount());
      mounted.container.remove();
      mounted = null;
    }
  });

  it('exibe estado vazio quando lista de contatos está vazia', () => {
    mockUsePageData.mockReturnValue({ data: { authenticated: true, user: { company_name: 'Acme' } }, loading: false, error: null });
    mockUseLogout.mockReturnValue({ logout: vi.fn() });
    mockUseContacts.mockReturnValue(baseContactsMock());

    mounted = mount(<ContactsPage />);
    expect(mounted.container.textContent).toContain('Nenhum contato encontrado');
  });

  it('abre modal e valida campos obrigatórios antes de criar', async () => {
    mockUsePageData.mockReturnValue({ data: { authenticated: true, user: { company_name: 'Acme' } }, loading: false, error: null });
    mockUseLogout.mockReturnValue({ logout: vi.fn() });
    mockCreateContact.mockResolvedValue({});
    mockUseContacts.mockReturnValue(baseContactsMock());

    mounted = mount(<ContactsPage />);

    const openModalButton = [...mounted.container.querySelectorAll('button')]
      .find((btn) => btn.textContent.includes('Novo contato'));
    expect(openModalButton).toBeTruthy();

    await act(async () => openModalButton.click());

    const nameInput = mounted.container.querySelector('#contacts-new-name');
    expect(nameInput).toBeTruthy();

    const submitButton = [...mounted.container.querySelectorAll('button')]
      .find((btn) => btn.textContent.includes('Salvar contato'));
    expect(submitButton).toBeTruthy();

    await act(async () => {
      const form = nameInput.closest('form');
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      await Promise.resolve();
    });

    expect(mockCreateContact).not.toHaveBeenCalled();
    expect(mounted.container.textContent).toContain('Preencha nome e telefone.');
  });
});
