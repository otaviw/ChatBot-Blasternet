import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '@/services/api';

const CONTACTS_ENDPOINT = '/minha-conta/contatos';
const CONTACTS_IMPORT_ENDPOINT = '/contacts/import';

const sortContactsByName = (items) =>
  [...items].sort((a, b) =>
    String(a?.name ?? '').localeCompare(String(b?.name ?? ''), 'pt-BR', { sensitivity: 'base' })
  );

const digitsOnly = (value) => String(value ?? '').replace(/\D/g, '');
const normalizeText = (value) => String(value ?? '').trim().toLowerCase();

async function fetchAllContacts() {
  const contacts = [];
  let page = 1;
  let lastPage = 1;

  do {
    const response = await api.get(CONTACTS_ENDPOINT, { params: { page } });
    const payload = response.data ?? {};

    if (Array.isArray(payload?.data)) {
      contacts.push(...payload.data);
    }

    const parsedLastPage = Number(payload?.last_page ?? 1);
    lastPage = Number.isFinite(parsedLastPage) && parsedLastPage > 0 ? parsedLastPage : 1;
    page += 1;
  } while (page <= lastPage);

  return sortContactsByName(contacts);
}

function useContacts() {
  const [allContacts, setAllContacts] = useState([]);
  const [searchQuery, setSearchQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [creating, setCreating] = useState(false);
  const [importing, setImporting] = useState(false);
  const [saving, setSaving] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const fetchContacts = useCallback(async () => {
    setLoading(true);
    setError('');

    try {
      const contacts = await fetchAllContacts();
      setAllContacts(contacts);
    } catch (err) {
      setAllContacts([]);
      setError(err?.response?.data?.message ?? 'Não foi possível carregar os contatos.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void fetchContacts();
  }, [fetchContacts]);

  const searchContacts = useCallback((query = '') => {
    setSearchQuery(String(query));
  }, []);

  const contacts = useMemo(() => {
    const normalizedQuery = normalizeText(searchQuery);
    if (!normalizedQuery) return allContacts;

    const numericQuery = digitsOnly(normalizedQuery);

    return allContacts.filter((contact) => {
      const contactName = normalizeText(contact?.name);
      const contactPhone = String(contact?.phone ?? '');
      const normalizedContactPhone = digitsOnly(contactPhone);

      return (
        contactName.includes(normalizedQuery) ||
        contactPhone.toLowerCase().includes(normalizedQuery) ||
        (numericQuery.length > 0 && normalizedContactPhone.includes(numericQuery))
      );
    });
  }, [allContacts, searchQuery]);

  const createContact = useCallback(async ({ name, phone }) => {
    const safeName = String(name ?? '').trim();
    const safePhone = String(phone ?? '').trim();

    setCreating(true);
    try {
      const response = await api.post(CONTACTS_ENDPOINT, {
        name: safeName,
        phone: safePhone,
      });

      const contact = response?.data?.contact;
      if (contact) {
        setAllContacts((previous) => sortContactsByName([...previous, contact]));
      } else {
        await fetchContacts();
      }
    } catch (err) {
      const message =
        err?.response?.data?.errors?.phone?.[0] ??
        err?.response?.data?.errors?.name?.[0] ??
        err?.response?.data?.message ??
        'Não foi possível criar o contato.';
      throw new Error(message);
    } finally {
      setCreating(false);
    }
  }, [fetchContacts]);

  const updateContact = useCallback(async (id, { name, phone }) => {
    setSaving(true);
    try {
      const response = await api.patch(`${CONTACTS_ENDPOINT}/${id}`, {
        name: String(name ?? '').trim(),
        phone: String(phone ?? '').trim(),
      });
      const updated = response?.data?.contact;
      if (updated) {
        setAllContacts((previous) =>
          sortContactsByName(previous.map((c) => (c.id === updated.id ? updated : c)))
        );
        return updated;
      }
      await fetchContacts();
    } catch (err) {
      const message =
        err?.response?.data?.errors?.phone?.[0] ??
        err?.response?.data?.errors?.name?.[0] ??
        err?.response?.data?.message ??
        'Não foi possível salvar o contato.';
      throw new Error(message);
    } finally {
      setSaving(false);
    }
  }, [fetchContacts]);

  const deleteContact = useCallback(async (id) => {
    setDeleting(true);
    try {
      await api.delete(`${CONTACTS_ENDPOINT}/${id}`);
      setAllContacts((previous) => previous.filter((c) => c.id !== id));
    } catch (err) {
      const message =
        err?.response?.data?.message ?? 'Não foi possível excluir o contato.';
      throw new Error(message);
    } finally {
      setDeleting(false);
    }
  }, []);

  const importCsv = useCallback(async (file) => {
    const formData = new FormData();
    formData.append('file', file);

    setImporting(true);
    try {
      const response = await api.post(CONTACTS_IMPORT_ENDPOINT, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });

      await fetchContacts();
      return response?.data ?? {};
    } catch (err) {
      const message = err?.response?.data?.message ?? 'Não foi possível importar o CSV.';
      throw new Error(message);
    } finally {
      setImporting(false);
    }
  }, [fetchContacts]);

  const refetch = fetchContacts;

  return useMemo(
    () => ({
      contacts,
      allContacts,
      searchQuery,
      loading,
      error,
      creating,
      importing,
      saving,
      deleting,
      fetchContacts,
      searchContacts,
      refetch,
      createContact,
      updateContact,
      deleteContact,
      importCsv,
    }),
    [
      contacts,
      allContacts,
      searchQuery,
      loading,
      error,
      creating,
      importing,
      saving,
      deleting,
      fetchContacts,
      searchContacts,
      refetch,
      createContact,
      updateContact,
      deleteContact,
      importCsv,
    ]
  );
}

export default useContacts;
