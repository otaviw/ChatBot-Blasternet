import './UsersPage.css';
import { useCallback, useState, useMemo, useEffect } from 'react';
import Layout from "@/components/layout/Layout/Layout.jsx";
import usePageData from "@/hooks/usePageData";
import useLogout from "@/hooks/useLogout";
import api from "@/services/api";
import Button from "@/components/ui/Button/Button.jsx";
import Card from "@/components/ui/Card/Card.jsx";
import PageHeader from "@/components/ui/PageHeader/PageHeader.jsx";
import UserFormFields from "@/components/sections/users/UserFormFields/UserFormFields.jsx";
import UsersListPanel from "@/components/sections/users/UsersListPanel/UsersListPanel.jsx";
import LoadingSkeleton from "@/components/ui/LoadingSkeleton/LoadingSkeleton.jsx";
import ConfirmDialog from "@/components/ui/ConfirmDialog/ConfirmDialog.jsx";
import { showError, showSuccess } from "@/services/toastService";

function initialForm(isAdminScope) {
  if (isAdminScope) {
    return {
      name: "",
      email: "",
      password: "",
      role: "agent",
      company_id: "",
      reseller_id: "",
      is_active: true,
      can_use_ai: false,
      areas: [],
      appointment_is_staff: true,
      appointment_display_name: "",
      permissions: null,
    };
  }

  return {
    name: "",
    email: "",
    password: "",
    role: "agent",
    is_active: true,
    can_use_ai: false,
    areas: [],
    appointment_is_staff: true,
    appointment_display_name: "",
    permissions: null,
  };
}

function normalizeAreaLabels(values) {
  const clean = (Array.isArray(values) ? values : [])
    .map((value) => String(value ?? "").trim())
    .filter(Boolean);

  return [...new Map(clean.map((label) => [label.toLowerCase(), label])).values()];
}

export default function UsersPage({ scope = "company" }) {
  const isAdminScope = scope === "admin";
  const usersEndpoint = isAdminScope ? "/admin/users" : "/minha-conta/users";
  const { data, loading, error } = usePageData(usersEndpoint);
  const { logout } = useLogout();

  const [users, setUsers] = useState([]);
  const [companies, setCompanies] = useState([]);
  const [resellers, setResellers] = useState([]);
  const [companyScopeAreas, setCompanyScopeAreas] = useState([]);
  const [dbAreas, setDbAreas] = useState([]);
  const [fullBotSettings, setFullBotSettings] = useState(null);
  const [newAreaName, setNewAreaName] = useState('');
  const [createAreaBusy, setCreateAreaBusy] = useState(false);
  const [deleteAreaBusy, setDeleteAreaBusy] = useState(null);
  const [extraLoading, setExtraLoading] = useState(true);
  const [extraError, setExtraError] = useState(null);
  const [adminUser, setAdminUser] = useState(null);

  const [createBusy, setCreateBusy] = useState(false);
  const [editBusy, setEditBusy] = useState(false);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [selectedUserId, setSelectedUserId] = useState(null);
  const [createForm, setCreateForm] = useState(initialForm(isAdminScope));
  const [editForm, setEditForm] = useState(null);
  const isSuperAdminManagingGlobalUsers = isAdminScope && adminUser?.role === "system_admin";

  useEffect(() => {
    setUsers(data?.users ?? []);
  }, [data]);

  useEffect(() => {
    let canceled = false;

    async function loadExtraData() {
      setExtraLoading(true);
      setExtraError(null);

      try {
        if (isAdminScope) {
          const [companiesResponse, resellersResponse, meResponse] = await Promise.all([
            api.get("/admin/empresas"),
            api.get("/admin/resellers"),
            api.get("/me"),
          ]);
          if (canceled) return;
          setCompanies(companiesResponse.data?.companies ?? []);
          setResellers(resellersResponse.data?.resellers ?? []);
          setAdminUser(meResponse?.data?.user ?? null);
          setCompanyScopeAreas([]);
        } else {
          const [botResponse, areasResponse] = await Promise.all([
            api.get("/minha-conta/bot"),
            api.get("/areas"),
          ]);
          if (canceled) return;

          const fullSettings = botResponse.data?.settings ?? {};
          const fromBot = fullSettings.service_areas ?? [];
          const rawAreas = areasResponse.data?.areas ?? [];
          const fromAreas = rawAreas.map((area) => area.name);

          setFullBotSettings(fullSettings);
          setDbAreas(rawAreas);
          setCompanyScopeAreas(normalizeAreaLabels([...fromBot, ...fromAreas]));
          setCompanies([]);
          setResellers([]);
          setAdminUser(null);
        }
      } catch (err) {
        if (canceled) return;

        const redirect = err.response?.data?.redirect;
        if (redirect) {
          window.location.href = redirect;
          return;
        }

        setExtraError(err);
      } finally {
        if (!canceled) {
          setExtraLoading(false);
        }
      }
    }

    loadExtraData();

    return () => {
      canceled = true;
    };
  }, [isAdminScope]);

  const companyName = data?.company?.name ?? "Empresa";

  const roleOptions = isAdminScope
    ? isSuperAdminManagingGlobalUsers
      ? [
          { value: "reseller_admin", label: "Admin revenda" },
          { value: "system_admin", label: "Superadmin" },
        ]
      : [
          { value: "agent", label: "Agente" },
          { value: "company_admin", label: "Admin da empresa" },
          { value: "reseller_admin", label: "Admin revenda" },
        ]
    : [
        { value: "agent", label: "Agente" },
        { value: "company_admin", label: "Admin da empresa" },
      ];

  useEffect(() => {
    if (!isSuperAdminManagingGlobalUsers) return;

    setCreateForm((prev) => {
      if (prev.role === "system_admin" || prev.role === "reseller_admin") {
        return prev;
      }
      return { ...prev, role: "reseller_admin", company_id: "", areas: [] };
    });

    setEditForm((prev) => {
      if (!prev) return prev;
      if (prev.role === "system_admin" || prev.role === "reseller_admin") {
        return prev;
      }
      return { ...prev, role: "reseller_admin", company_id: "", areas: [] };
    });
  }, [isSuperAdminManagingGlobalUsers]);

  function roleLabel(role) {
    if (role === "system_admin") return "superadmin";
    if (role === "reseller_admin") return "admin_revenda";
    if (role === "company_admin") return "admin_empresa";
    if (role === "agent") return "agente";
    return role;
  }

  const getCompanyAreas = useCallback((companyId) => {
    if (!companyId) return [];

    const company = companies.find((item) => String(item.id) === String(companyId));
    const areas = company?.bot_setting?.service_areas ?? company?.botSetting?.service_areas ?? [];

    return normalizeAreaLabels(areas);
  }, [companies]);

  const getResellerIdForCompany = useCallback((companyId) => {
    if (!companyId) return "";

    const company = companies.find((item) => String(item.id) === String(companyId));
    return company?.reseller_id ? String(company.reseller_id) : "";
  }, [companies]);

  const createNeedsCompany = isAdminScope
    ? createForm.role !== "system_admin" && createForm.role !== "reseller_admin"
    : false;
  const editNeedsCompany = isAdminScope && editForm
    ? editForm.role !== "system_admin" && editForm.role !== "reseller_admin"
    : false;
  const createNeedsReseller = isAdminScope && createForm.role === "reseller_admin";
  const editNeedsReseller = isAdminScope && editForm?.role === "reseller_admin";
  const currentAdminIsReseller = isAdminScope && adminUser?.role === "reseller_admin";

  const createAvailableAreas = useMemo(() => {
    if (isAdminScope) {
      if (createForm.role === "reseller_admin") return [];
      return getCompanyAreas(createForm.company_id);
    }
    return companyScopeAreas;
  }, [getCompanyAreas, isAdminScope, createForm.company_id, createForm.role, companyScopeAreas]);

  const editAvailableAreas = useMemo(() => {
    if (isAdminScope) {
      if (editForm?.role === "reseller_admin") return [];
      return getCompanyAreas(editForm?.company_id);
    }
    return companyScopeAreas;
  }, [editForm?.company_id, editForm?.role, getCompanyAreas, isAdminScope, companyScopeAreas]);

  function toggleCreateArea(area) {
    setCreateForm((prev) => {
      const exists = (prev.areas ?? []).includes(area);
      const areas = exists
        ? (prev.areas ?? []).filter((item) => item !== area)
        : [...(prev.areas ?? []), area];
      return { ...prev, areas };
    });
  }

  function toggleEditArea(area) {
    setEditForm((prev) => {
      if (!prev) return prev;
      const exists = (prev.areas ?? []).includes(area);
      const areas = exists
        ? (prev.areas ?? []).filter((item) => item !== area)
        : [...(prev.areas ?? []), area];
      return { ...prev, areas };
    });
  }

  function buildPayload(form) {
    const isAgent = form.role === "agent";

    if (!isAdminScope) {
      const payload = {
        ...form,
        areas: Array.isArray(form.areas) ? form.areas : [],
        permissions: isAgent ? (form.permissions ?? null) : null,
      };
      delete payload.company_id;
      delete payload.reseller_id;
      return payload;
    }

    const needsCompany = form.role !== "system_admin";

    const payload = {
      ...form,
      company_id: form.role === "reseller_admin" ? null : (needsCompany && form.company_id ? Number(form.company_id) : null),
      reseller_id: form.role === "reseller_admin"
        ? (currentAdminIsReseller
          ? (adminUser?.reseller_id
            ? Number(adminUser.reseller_id)
            : (form.reseller_id ? Number(form.reseller_id) : null))
          : (form.reseller_id ? Number(form.reseller_id) : null))
        : null,
      areas: form.role === "reseller_admin" ? [] : (needsCompany ? form.areas ?? [] : []),
      permissions: isAgent ? (form.permissions ?? null) : null,
    };
    return payload;
  }

  async function refreshUsers() {
    const response = await api.get(usersEndpoint);
    setUsers(response.data?.users ?? []);
  }

  async function handleCreate(event) {
    event.preventDefault();
    setCreateBusy(true);

    try {
      const payload = buildPayload(createForm);
      await api.post(usersEndpoint, payload);
      await refreshUsers();
      setCreateForm(initialForm(isAdminScope));
      showSuccess("Usuário criado com sucesso.");
    } catch (err) {
      showError(err.response?.data?.message || "Falha ao criar usuário.");
    } finally {
      setCreateBusy(false);
    }
  }

  function beginEdit(user) {
    setSelectedUserId(user.id);

    if (isAdminScope) {
      setEditForm({
        id: user.id,
        name: user.name,
        email: user.email,
        password: "",
        role: user.role,
        company_id: user.company_id ? String(user.company_id) : "",
        reseller_id: user.reseller_id
          ? String(user.reseller_id)
          : (user.company_id ? getResellerIdForCompany(user.company_id) : ""),
        is_active: Boolean(user.is_active),
        can_use_ai: Boolean(user.can_use_ai),
        areas: Array.isArray(user.areas) ? user.areas : [],
        appointment_is_staff: Boolean(user.appointment_is_staff),
        appointment_display_name: user.appointment_display_name || "",
        permissions: user.permissions ?? null,
      });
      return;
    }

    setEditForm({
      id: user.id,
      name: user.name,
      email: user.email,
      password: "",
      role: user.role === "company_admin" ? "company_admin" : "agent",
      is_active: Boolean(user.is_active),
      can_use_ai: Boolean(user.can_use_ai),
      areas: Array.isArray(user.areas) ? user.areas : [],
      appointment_is_staff: Boolean(user.appointment_is_staff),
      appointment_display_name: user.appointment_display_name || "",
      permissions: user.permissions ?? null,
    });
  }

  async function handleEdit(event) {
    event.preventDefault();
    if (!editForm?.id) return;

    setEditBusy(true);

    try {
      const payload = buildPayload(editForm);
      if (!payload.password) {
        delete payload.password;
      }

      await api.put(`${usersEndpoint}/${editForm.id}`, payload);
      await refreshUsers();
      setSelectedUserId(null);
      setEditForm(null);
      showSuccess("Usuário atualizado com sucesso.");
    } catch (err) {
      showError(err.response?.data?.message || "Falha ao atualizar usuário.");
    } finally {
      setEditBusy(false);
    }
  }

  function requestDelete(user) {
    if (!user?.id) return;
    setDeleteTarget(user);
  }

  async function confirmDelete() {
    if (!deleteTarget?.id) return;

    setDeleteBusy(true);
    try {
      await api.delete(`${usersEndpoint}/${deleteTarget.id}`);
      await refreshUsers();
      setDeleteTarget(null);
      showSuccess("Usuário excluido com sucesso.");
    } catch (err) {
      showError(err.response?.data?.message || "Falha ao excluir usuário.");
    } finally {
      setDeleteBusy(false);
    }
  }

  async function refreshDbAreas() {
    const response = await api.get('/areas');
    const areas = response.data?.areas ?? [];
    setDbAreas(areas);
    const fromAreas = areas.map((a) => a.name);
    const fromBot = fullBotSettings?.service_areas ?? [];
    setCompanyScopeAreas(normalizeAreaLabels([...fromBot, ...fromAreas]));
  }

  async function handleCreateArea(event) {
    event.preventDefault();
    if (!newAreaName.trim()) return;
    setCreateAreaBusy(true);
    try {
      await api.post('/areas', { name: newAreaName.trim() });
      await refreshDbAreas();
      setNewAreaName('');
      showSuccess('Área criada com sucesso.');
    } catch (err) {
      showError(err.response?.data?.message || 'Falha ao criar área.');
    } finally {
      setCreateAreaBusy(false);
    }
  }

  async function handleDeleteArea(areaId) {
    setDeleteAreaBusy(areaId);
    try {
      await api.delete(`/areas/${areaId}`);
      await refreshDbAreas();
      showSuccess('Área excluída com sucesso.');
    } catch (err) {
      showError(err.response?.data?.message || 'Falha ao excluir área.');
    } finally {
      setDeleteAreaBusy(null);
    }
  }

  const loadingAny = loading || extraLoading;
  const pageError = error || extraError;
  const pageErrorMessage = pageError?.response?.data?.message || "Não foi possível carregar usuários.";

  if (loadingAny) {
    return (
      <Layout
        role={isAdminScope ? "admin" : "company"}
        companyName={isAdminScope ? null : companyName}
        onLogout={logout}
      >
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {Array.from({ length: 2 }).map((_, index) => (
            <Card key={`users-page-skeleton-${index}`}>
              <LoadingSkeleton className="h-5 w-40" />
              <LoadingSkeleton className="mt-4 h-10 w-full" />
              <LoadingSkeleton className="mt-3 h-10 w-full" />
              <LoadingSkeleton className="mt-3 h-10 w-10/12" />
              <LoadingSkeleton className="mt-5 h-9 w-36" />
            </Card>
          ))}
        </div>
      </Layout>
    );
  }

  if (pageError || !data?.authenticated) {
    return (
      <Layout
        role={isAdminScope ? "admin" : "company"}
        companyName={isAdminScope ? null : companyName}
        onLogout={logout}
      >
        <p className="text-sm text-red-600">{pageErrorMessage}</p>
      </Layout>
    );
  }

  const pageTitle = isAdminScope ? "Usuários" : "Usuários da empresa";
  const pageSubtitle = isAdminScope
    ? "Gerencie acessos globais com perfis e áreas alinhadas por empresa."
    : "Crie, edite e organize o time por perfil e áreas de atuação.";

  const createAreaMessage = isAdminScope
    ? createForm.company_id
      ? "Empresa sem áreas cadastradas. Configure em Config. do bot da empresa."
      : "Selecione a empresa para escolher as áreas."
    : "Empresa sem áreas cadastradas. Configure em Config. do bot da empresa.";

  const editAreaMessage = isAdminScope
    ? editForm?.company_id
      ? "Empresa sem áreas cadastradas. Configure em Config. do bot da empresa."
      : "Selecione a empresa para escolher as áreas."
    : "Empresa sem áreas cadastradas. Configure em Config. do bot da empresa.";

  return (
    <Layout
      role={isAdminScope ? "admin" : "company"}
      companyName={isAdminScope ? null : companyName}
      onLogout={logout}
    >
      <PageHeader title={pageTitle} subtitle={pageSubtitle} />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Card>
          <h2 className="text-base font-semibold text-[#0f172a] mb-3">Criar usuário</h2>
          <form onSubmit={handleCreate} className="space-y-3">
            <UserFormFields
              form={createForm}
              setForm={setCreateForm}
              roleOptions={roleOptions}
              passwordRequired
              passwordPlaceholder="Senha (min 8)"
              showCompanyField={createNeedsCompany}
              companies={companies}
              onCompanyChange={(companyId) =>
                setCreateForm((prev) => {
                  if (!isAdminScope) return prev;
                  const allowedAreas = getCompanyAreas(companyId);
                  const currentAreas = Array.isArray(prev.areas) ? prev.areas : [];

                  return {
                    ...prev,
                    company_id: companyId,
                    reseller_id: "",
                    areas: currentAreas.filter((area) => allowedAreas.includes(area)),
                  };
                })
              }
              showResellerField={createNeedsReseller && !currentAdminIsReseller}
              resellers={resellers}
                onResellerChange={(resellerId) =>
                  setCreateForm((prev) => ({
                    ...prev,
                  reseller_id: resellerId,
                  company_id: "",
                  areas: [],
                }))
              }
              showAreas={isAdminScope ? createNeedsCompany : true}
              availableAreas={createAvailableAreas}
              onToggleArea={toggleCreateArea}
              areaEmptyMessage={createAreaMessage}
              showAiPermissionField={createForm.role === "agent"}
              showAppointmentFields={!isAdminScope}
              showPermissionsField={createForm.role === "agent"}
            />

            <Button type="submit" variant="primary" disabled={createBusy}>
              {createBusy ? "Criando..." : "Criar usuário"}
            </Button>

          </form>
        </Card>

        <Card>
          <h2 className="text-base font-semibold text-[#0f172a] mb-3">Usuários cadastrados</h2>
          <UsersListPanel
            users={users}
            roleLabel={roleLabel}
            onEdit={beginEdit}
            onDelete={requestDelete}
            showCompany={isAdminScope}
          />

          {editForm && (
            <form onSubmit={handleEdit} className="space-y-3 border-t border-[#e2e8f0] pt-4">
              <h3 className="text-sm font-semibold text-[#0f172a]">Editar usuário #{selectedUserId}</h3>

              <UserFormFields
                form={editForm}
                setForm={setEditForm}
                roleOptions={roleOptions}
                passwordRequired={false}
                passwordPlaceholder="Nova senha (opcional)"
                showCompanyField={editNeedsCompany}
                companies={companies}
                onCompanyChange={(companyId) =>
                  setEditForm((prev) => {
                    if (!prev || !isAdminScope) return prev;

                    const allowedAreas = getCompanyAreas(companyId);
                    const currentAreas = Array.isArray(prev.areas) ? prev.areas : [];

                    return {
                      ...prev,
                      company_id: companyId,
                      reseller_id: "",
                      areas: currentAreas.filter((area) => allowedAreas.includes(area)),
                    };
                  })
                }
                showResellerField={editNeedsReseller && !currentAdminIsReseller}
              resellers={resellers}
                onResellerChange={(resellerId) =>
                  setEditForm((prev) => (prev ? {
                    ...prev,
                    reseller_id: resellerId,
                    company_id: "",
                    areas: [],
                  } : prev))
                }
                showAreas={isAdminScope ? editNeedsCompany : true}
                availableAreas={editAvailableAreas}
                onToggleArea={toggleEditArea}
                areaEmptyMessage={editAreaMessage}
                showAiPermissionField={editForm.role === "agent"}
                showAppointmentFields={!isAdminScope}
                showPermissionsField={editForm.role === "agent"}
              />

              <Button type="submit" variant="primary" disabled={editBusy}>
                {editBusy ? "Salvando..." : "Salvar usuário"}
              </Button>

            </form>
          )}
        </Card>
      </div>

      {!isAdminScope && (
        <>
          <PageHeader
            title="Áreas de atendimento"
            subtitle="Crie e organize as áreas usadas em transferências, menus e vínculos de usuário."
          />

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <Card>
              <h2 className="text-base font-semibold text-[#0f172a] mb-1">Criar área</h2>
              <p className="text-sm text-[#64748b] mb-4">
                Áreas cadastradas aqui ficam disponíveis para vincular usuários e configurar transferências no bot.
              </p>
              <form onSubmit={handleCreateArea} className="space-y-3">
                <div>
                  <label className="block text-sm font-medium text-[#374151] mb-1">Nome da área</label>
                  <input
                    type="text"
                    value={newAreaName}
                    onChange={(e) => setNewAreaName(e.target.value)}
                    placeholder="Ex.: Suporte, Vendas, Financeiro"
                    className="w-full rounded-md border border-[#e2e8f0] bg-white px-3 py-2 text-sm text-[#0f172a] placeholder-[#94a3b8] focus:outline-none focus:ring-2 focus:ring-[#2563eb] focus:border-transparent"
                  />
                </div>
                <Button type="submit" variant="primary" disabled={createAreaBusy || !newAreaName.trim()}>
                  {createAreaBusy ? 'Criando...' : 'Criar área'}
                </Button>
              </form>
            </Card>

            <Card>
              <h2 className="text-base font-semibold text-[#0f172a] mb-3">Áreas cadastradas</h2>
              {dbAreas.length === 0 ? (
                <p className="text-sm text-[#64748b]">Nenhuma área cadastrada ainda.</p>
              ) : (
                <ul className="space-y-2">
                  {dbAreas.map((area) => (
                    <li
                      key={area.id}
                      className="flex items-center justify-between gap-3 rounded-md border border-[#e2e8f0] px-3 py-2"
                    >
                      <div>
                          <span className="text-sm font-medium text-[#0f172a]">{area.name}</span>
                          <span className="ml-2 text-xs text-[#94a3b8]">
                            {area.users_count ?? 0} usuário{(area.users_count ?? 0) !== 1 ? 's' : ''}
                          </span>
                        </div>
                        <button
                          type="button"
                          onClick={() => handleDeleteArea(area.id)}
                          disabled={deleteAreaBusy === area.id}
                          className="text-xs text-red-600 hover:text-red-800 disabled:opacity-50 transition-colors"
                        >
                          {deleteAreaBusy === area.id ? 'Excluindo...' : 'Excluir'}
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
          </div>
        </>
      )}

      <ConfirmDialog
        open={Boolean(deleteTarget)}
        title="Excluir usuário"
        description={
          deleteTarget
            ? `Tem certeza que deseja excluir "${deleteTarget.name ?? "este usuário"}"?`
            : ""
        }
        confirmLabel="Excluir"
        confirmTone="danger"
        busy={deleteBusy}
        onClose={() => {
          if (!deleteBusy) setDeleteTarget(null);
        }}
        onConfirm={() => void confirmDelete()}
      />
    </Layout>
  );
}


