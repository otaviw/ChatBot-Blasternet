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
  const [companyScopeAreas, setCompanyScopeAreas] = useState([]);
  const [extraLoading, setExtraLoading] = useState(true);
  const [extraError, setExtraError] = useState(null);

  const [createBusy, setCreateBusy] = useState(false);
  const [editBusy, setEditBusy] = useState(false);
  const [deleteBusy, setDeleteBusy] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState(null);
  const [selectedUserId, setSelectedUserId] = useState(null);
  const [createForm, setCreateForm] = useState(initialForm(isAdminScope));
  const [editForm, setEditForm] = useState(null);

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
          const response = await api.get("/admin/empresas");
          if (canceled) return;
          setCompanies(response.data?.companies ?? []);
          setCompanyScopeAreas([]);
        } else {
          const [botResponse, areasResponse] = await Promise.all([
            api.get("/minha-conta/bot"),
            api.get("/areas"),
          ]);
          if (canceled) return;

          const fromBot = botResponse.data?.settings?.service_areas ?? [];
          const fromAreas = (areasResponse.data?.areas ?? []).map((area) => area.name);

          setCompanyScopeAreas(normalizeAreaLabels([...fromBot, ...fromAreas]));
          setCompanies([]);
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
    ? [
        { value: "agent", label: "Agente" },
        { value: "company_admin", label: "Admin da empresa" },
        { value: "system_admin", label: "Superadmin" },
      ]
    : [
        { value: "agent", label: "Agente" },
        { value: "company_admin", label: "Admin da empresa" },
      ];

  function roleLabel(role) {
    if (role === "system_admin") return "superadmin";
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

  const createNeedsCompany = isAdminScope ? createForm.role !== "system_admin" : false;
  const editNeedsCompany = isAdminScope && editForm ? editForm.role !== "system_admin" : false;

  const createAvailableAreas = useMemo(() => {
    if (isAdminScope) {
      return getCompanyAreas(createForm.company_id);
    }
    return companyScopeAreas;
  }, [getCompanyAreas, isAdminScope, createForm.company_id, companyScopeAreas]);

  const editAvailableAreas = useMemo(() => {
    if (isAdminScope) {
      return getCompanyAreas(editForm?.company_id);
    }
    return companyScopeAreas;
  }, [editForm?.company_id, getCompanyAreas, isAdminScope, companyScopeAreas]);

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
      return payload;
    }

    const needsCompany = form.role !== "system_admin";

    return {
      ...form,
      company_id: needsCompany && form.company_id ? Number(form.company_id) : null,
      areas: needsCompany ? form.areas ?? [] : [],
      permissions: isAgent ? (form.permissions ?? null) : null,
    };
  }

  async function refreshUsers() {
    const response = await api.get(usersEndpoint);
    setUsers(response.data?.users ?? []);
  }

  async function handleCreate(event) {
    event.preventDefault();
    setCreateBusy(true);

    try {
      await api.post(usersEndpoint, buildPayload(createForm));
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
                    areas: currentAreas.filter((area) => allowedAreas.includes(area)),
                  };
                })
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
                      areas: currentAreas.filter((area) => allowedAreas.includes(area)),
                    };
                  })
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


