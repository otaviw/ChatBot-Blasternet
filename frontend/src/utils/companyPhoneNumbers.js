const listOrEmpty = (value) => (Array.isArray(value) ? value : []);

export function normalizeCompanyNumbers(company) {
  const rawList = listOrEmpty(
    company?.whatsapp_numbers
    ?? company?.phone_numbers
    ?? company?.sender_numbers
  );

  if (rawList.length > 0) {
    return rawList
      .map((item) => {
        const id = String(item?.id ?? item?.phone_number_id ?? item?.value ?? '').trim();
        const label = String(item?.display_phone_number ?? item?.phone ?? item?.label ?? id).trim();
        return {
          id,
          label,
          is_active: Boolean(item?.is_active ?? true),
          is_primary: Boolean(item?.is_primary ?? false),
        };
      })
      .filter((item) => item.id !== '');
  }

  const fallback = String(company?.meta_phone_number_id ?? '').trim();
  if (!fallback) return [];
  return [{ id: fallback, label: fallback, is_active: true, is_primary: true }];
}

export function getActiveCompanyNumbers(company) {
  return normalizeCompanyNumbers(company).filter((item) => item.is_active);
}
