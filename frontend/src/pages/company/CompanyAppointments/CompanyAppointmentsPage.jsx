import { useEffect, useMemo, useRef, useState } from 'react';
import './CompanyAppointmentsPage.css';
import Layout from '@/components/layout/Layout/Layout.jsx';
import PageLoading from '@/components/ui/PageLoading/PageLoading.jsx';
import usePageData from '@/hooks/usePageData';
import useLogout from '@/hooks/useLogout';
import realtimeClient from '@/services/realtimeClient';
import { REALTIME_EVENTS } from '@/constants/realtimeEvents';
import {
  createAppointment,
  createAppointmentService,
  createTimeOff,
  deleteTimeOff,
  fetchAppointmentAvailability,
  fetchAppointments,
  fetchAppointmentServices,
  fetchAppointmentSettings,
  fetchAppointmentStaff,
  fetchTimeOffs,
  replaceStaffWorkingHours,
  updateAppointmentService,
  updateAppointmentSettings,
  updateAppointmentStatus,
  unwrapError,
} from '@/services/appointmentService';

const DAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sab'];
const ymd = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parseYmd = (v) => new Date(`${v}T00:00:00`);
const plusDays = (v, n) => { const d = parseYmd(v); d.setDate(d.getDate() + n); return ymd(d); };
const weekStart = (v) => plusDays(v, -parseYmd(v).getDay());
const weekEnd = (v) => plusDays(weekStart(v), 6);
const monthStart = (v) => { const d = parseYmd(v); d.setDate(1); return ymd(d); };
const monthEnd = (v) => { const d = parseYmd(v); d.setMonth(d.getMonth() + 1, 0); return ymd(d); };
const STATUS_LABEL = { pending: 'Pendente', confirmed: 'Confirmado', completed: 'Concluído', cancelled: 'Cancelado', no_show: 'Não compareceu', rescheduled: 'Reagendado' };
const isPast = (v) => v && new Date(v) < new Date();
const fmtDt = (v) => (!v ? '-' : new Date(v).toLocaleString('pt-BR'));
const fmtHm = (v) => (!v ? '--:--' : new Date(v).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }));
const fmtYmd = (v) => parseYmd(v).toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit' });
const toLocalInput = (v) => {
  if (!v) return '';
  const d = new Date(v); if (Number.isNaN(d.getTime())) return '';
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
};
const DURATION_UNITS = [
  { value: 'minutes', label: 'minutos', factor: 1 },
  { value: 'hours',   label: 'horas',   factor: 60 },
  { value: 'days',    label: 'dias',    factor: 1440 },
];

function minutesToUnit(minutes) {
  const m = Number(minutes) || 0;
  if (m === 0) return { amount: 0, unit: 'minutes' };
  if (m % 1440 === 0) return { amount: m / 1440, unit: 'days' };
  if (m % 60 === 0)   return { amount: m / 60,   unit: 'hours' };
  return { amount: m, unit: 'minutes' };
}

function DurationInput({ valueMinutes, onChange, id }) {
  const { amount, unit } = minutesToUnit(valueMinutes);
  const factor = DURATION_UNITS.find((u) => u.value === unit)?.factor ?? 1;

  const handleAmount = (e) => {
    const n = Math.max(0, Number(e.target.value) || 0);
    onChange(n * factor);
  };
  const handleUnit = (e) => {
    const newFactor = DURATION_UNITS.find((u) => u.value === e.target.value)?.factor ?? 1;
    onChange(amount * newFactor);
  };

  return (
    <div style={{ display: 'flex', gap: '6px', alignItems: 'center' }}>
      <input
        id={id}
        type="number"
        min="0"
        value={amount}
        onChange={handleAmount}
        style={{ flex: '1 1 70px', minWidth: 0 }}
      />
      <select value={unit} onChange={handleUnit} style={{ flex: '0 0 auto' }}>
        {DURATION_UNITS.map((u) => (
          <option key={u.value} value={u.value}>{u.label}</option>
        ))}
      </select>
    </div>
  );
}

const initHours = () => Array.from({ length: 7 }, (_, i) => ({ day_of_week: i, is_active: false, start_time: '09:00', break_start_time: '', break_end_time: '', end_time: '18:00' }));
const mapHours = (s) => {
  const base = initHours();
  (s?.working_hours || []).forEach((h) => { const d = Number(h.day_of_week); if (d >= 0 && d <= 6) base[d] = { day_of_week: d, is_active: !!h.is_active, start_time: h.start_time || '09:00', break_start_time: h.break_start_time || '', break_end_time: h.break_end_time || '', end_time: h.end_time || '18:00' }; });
  return base;
};

export default function CompanyAppointmentsPage() {
  const { data, loading, error } = usePageData('/me');
  const { logout } = useLogout();
  const today = useMemo(() => ymd(new Date()), []);

  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState('');
  const [errMsg, setErrMsg] = useState('');
  const [settings, setSettings] = useState(null);
  const [service, setService] = useState(null);
  const [serviceForm, setServiceForm] = useState({ name: 'Atendimento', description: '', duration_minutes: 30, is_active: true });
  const [staff, setStaff] = useState([]);
  const [selectedStaffId, setSelectedStaffId] = useState('');
  const [hours, setHours] = useState(initHours);
  const [availDate, setAvailDate] = useState(today);
  const [availability, setAvailability] = useState([]);
  const [timeOffs, setTimeOffs] = useState([]);
  const [appointments, setAppointments] = useState([]);
  const [calendarView, setCalendarView] = useState('week');
  const [anchorDate, setAnchorDate] = useState(today);
  const [newTimeOff, setNewTimeOff] = useState({ staff_profile_id: '', starts_at: '', ends_at: '', reason: '' });
  const [newAppt, setNewAppt] = useState({ starts_at: '', customer_name: '', customer_phone: '' });

  const canManage = !!(data?.user?.role === 'company_admin' || data?.user?.role === 'agent');
  const serviceId = service?.id ? String(service.id) : '';

  const calRange = useMemo(() => {
    if (calendarView === 'month') {
      const start = weekStart(monthStart(anchorDate));
      const end = weekEnd(monthEnd(anchorDate));
      return { start, end };
    }
    return { start: weekStart(anchorDate), end: weekEnd(anchorDate) };
  }, [calendarView, anchorDate]);

  const calDays = useMemo(() => {
    const out = []; let c = calRange.start;
    while (c <= calRange.end) { out.push(c); c = plusDays(c, 1); }
    return out;
  }, [calRange]);

  const apptFormWarning = useMemo(() => {
    if (!newAppt.starts_at) return null;
    const dt = new Date(newAppt.starts_at);
    if (Number.isNaN(dt.getTime())) return null;

    const dow = dt.getDay();
    const hourEntry = (hours ?? []).find((h) => h.day_of_week === dow);

    if (!hourEntry?.is_active) return 'Atendente não trabalha neste dia.';

    const hh = String(dt.getHours()).padStart(2, '0');
    const mm = String(dt.getMinutes()).padStart(2, '0');
    const timeStr = `${hh}:${mm}`;

    if (hourEntry.start_time && timeStr < hourEntry.start_time) {
      return `Fora do horário de trabalho. Início: ${hourEntry.start_time}.`;
    }

    const durationMin = service?.duration_minutes ?? 0;
    const endDt = new Date(dt.getTime() + durationMin * 60000);
    const endStr = `${String(endDt.getHours()).padStart(2, '0')}:${String(endDt.getMinutes()).padStart(2, '0')}`;
    if (hourEntry.end_time && endStr > hourEntry.end_time) {
      return `Atendimento ultrapassaria o fim do expediente (${hourEntry.end_time}).`;
    }

    if (hourEntry.break_start_time && hourEntry.break_end_time
        && timeStr >= hourEntry.break_start_time && timeStr < hourEntry.break_end_time) {
      return `Horário de intervalo (${hourEntry.break_start_time}–${hourEntry.break_end_time}).`;
    }

    if (settings?.booking_min_notice_minutes) {
      const earliest = new Date(Date.now() + settings.booking_min_notice_minutes * 60000);
      if (dt < earliest) {
        const { amount, unit } = minutesToUnit(settings.booking_min_notice_minutes);
        const unitLabel = DURATION_UNITS.find((u) => u.value === unit)?.label ?? 'minutos';
        return `Antecedência mínima: ${amount} ${unitLabel}.`;
      }
    }

    if (settings?.booking_max_advance_days) {
      const latest = new Date(Date.now() + settings.booking_max_advance_days * 24 * 60 * 60 * 1000);
      if (dt > latest) {
        return `Máximo ${settings.booking_max_advance_days} dias de antecedência.`;
      }
    }

    return null;
  }, [newAppt.starts_at, hours, settings, service]);

  const apptByDay = useMemo(() => {
    const map = {};
    appointments.forEach((a) => { const d = a?.starts_at ? ymd(new Date(a.starts_at)) : null; if (!d) return; if (!map[d]) map[d] = []; map[d].push(a); });
    Object.keys(map).forEach((k) => map[k].sort((a, b) => new Date(a.starts_at).getTime() - new Date(b.starts_at).getTime()));
    return map;
  }, [appointments]);

  const run = async (fn, fallback) => { setBusy(true); setErrMsg(''); try { await fn(); } catch (e) { setErrMsg(unwrapError(e, fallback)); } finally { setBusy(false); } };

  const reloadBase = async () => run(async () => {
    const [s1, s2, s3] = await Promise.all([fetchAppointmentSettings(), fetchAppointmentServices(), fetchAppointmentStaff()]);
    const nextSettings = s1?.settings ?? null;
    const nextService = (s2?.services || [])[0] || null;
    const nextStaff = s3?.staff || [];
    setSettings(nextSettings); setService(nextService); setStaff(nextStaff);
    if (nextService) setServiceForm({ name: nextService.name || 'Atendimento', description: nextService.description || '', duration_minutes: Number(nextService.duration_minutes || 30), is_active: !!nextService.is_active });
    const firstBookable = nextStaff.find((s) => s.is_bookable);
    if (!selectedStaffId && firstBookable) setSelectedStaffId(String(firstBookable.id));
  }, 'Não foi possível carregar dados de agendamento.');

  const reloadCalendar = async () => run(async () => {
    const [a1, a2] = await Promise.all([
      fetchAppointments({ date_from: calRange.start, date_to: calRange.end, staff_profile_id: selectedStaffId || undefined, per_page: 100 }),
      fetchTimeOffs({ date_from: calRange.start, date_to: calRange.end, staff_profile_id: selectedStaffId || undefined }),
    ]);
    setAppointments(a1?.items || []); setTimeOffs(a2?.time_offs || []);
  }, 'Não foi possível carregar calendário.');

  const reloadAvailability = async () => {
    if (!serviceId) { setAvailability([]); return; }
    await run(async () => {
      const r = await fetchAppointmentAvailability({ service_id: Number(serviceId), date: availDate, staff_profile_id: selectedStaffId || undefined });
      setAvailability(r?.staff || []);
    }, 'Não foi possível carregar horários livres.');
  };

  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { if (data?.authenticated) void reloadBase(); }, [data?.authenticated]);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { if (data?.authenticated) void reloadCalendar(); }, [data?.authenticated, calRange.start, calRange.end, selectedStaffId]);
  // eslint-disable-next-line react-hooks/exhaustive-deps
  useEffect(() => { if (data?.authenticated) void reloadAvailability(); }, [data?.authenticated, serviceId, availDate, selectedStaffId]);
  useEffect(() => {
    const t = staff.find((x) => String(x.id) === selectedStaffId);
    setHours(t ? mapHours(t) : initHours());
  }, [selectedStaffId, staff]);

  const reloadCalendarRef = useRef(reloadCalendar);
  useEffect(() => { reloadCalendarRef.current = reloadCalendar; });
  useEffect(() => {
    if (!data?.authenticated) return;
    const handle = () => void reloadCalendarRef.current();
    const unsubCreate = realtimeClient.on(REALTIME_EVENTS.APPOINTMENT_CREATED, handle);
    const unsubUpdate = realtimeClient.on(REALTIME_EVENTS.APPOINTMENT_UPDATED, handle);
    return () => { unsubCreate(); unsubUpdate(); };
  }, [data?.authenticated]);

  const saveSettings = () => run(async () => {
    const payload = { timezone: settings.timezone, slot_interval_minutes: Number(settings.slot_interval_minutes), booking_min_notice_minutes: Number(settings.booking_min_notice_minutes), booking_max_advance_days: Number(settings.booking_max_advance_days), cancellation_min_notice_minutes: Number(settings.cancellation_min_notice_minutes), reschedule_min_notice_minutes: Number(settings.reschedule_min_notice_minutes), allow_customer_choose_staff: !!settings.allow_customer_choose_staff };
    const r = await updateAppointmentSettings(payload); setSettings(r?.settings || settings); setMsg('Configurações salvas.'); await reloadAvailability();
  }, 'Falha ao salvar configurações.');

  const saveService = (e) => run(async () => {
    e.preventDefault(); const payload = { ...serviceForm, duration_minutes: Number(serviceForm.duration_minutes), buffer_before_minutes: 0, buffer_after_minutes: 0, max_bookings_per_slot: 1, is_active: !!serviceForm.is_active };
    const r = service?.id ? await updateAppointmentService(service.id, payload) : await createAppointmentService(payload);
    setService(r?.service || service); setMsg(service?.id ? 'Serviço atualizado.' : 'Serviço criado.'); await reloadAvailability();
  }, 'Falha ao salvar serviço.');

  const saveHours = (e) => run(async () => {
    e.preventDefault(); if (!selectedStaffId) throw new Error('Selecione um atendente.');
    const payload = {
      hours: hours.filter((h) => h.is_active).map((h) => ({
        day_of_week: h.day_of_week,
        start_time: h.start_time,
        break_start_time: h.break_start_time || null,
        break_end_time: h.break_end_time || null,
        end_time: h.end_time,
        is_active: true,
      })),
    };
    const r = await replaceStaffWorkingHours(Number(selectedStaffId), payload); const updated = r?.staff;
    if (updated) { setStaff((prev) => prev.map((x) => (String(x.id) === String(updated.id) ? updated : x))); setHours(mapHours(updated)); }
    setMsg('Jornada atualizada.'); await reloadAvailability();
  }, 'Falha ao salvar jornada.');

  const addTimeOff = (e) => run(async () => {
    e.preventDefault(); await createTimeOff({ staff_profile_id: newTimeOff.staff_profile_id ? Number(newTimeOff.staff_profile_id) : null, starts_at: newTimeOff.starts_at, ends_at: newTimeOff.ends_at, reason: newTimeOff.reason, source: 'manual' });
    setNewTimeOff({ staff_profile_id: '', starts_at: '', ends_at: '', reason: '' }); setMsg('Bloqueio registrado.'); await Promise.all([reloadCalendar(), reloadAvailability()]);
  }, 'Falha ao criar bloqueio.');

  const removeTimeOff = (id) => run(async () => { await deleteTimeOff(Number(id)); setMsg('Bloqueio removido.'); await Promise.all([reloadCalendar(), reloadAvailability()]); }, 'Falha ao remover bloqueio.');

  const addAppointment = (e) => run(async () => {
    e.preventDefault();
    if (!serviceId || !selectedStaffId) {
      throw new Error('Selecione serviçnão é atendente para criar agendamento.');
    }
    await createAppointment({ service_id: Number(serviceId), staff_profile_id: Number(selectedStaffId), starts_at: newAppt.starts_at, customer_name: newAppt.customer_name, customer_phone: newAppt.customer_phone, source: 'dashboard' });
    setNewAppt({ starts_at: '', customer_name: '', customer_phone: '' }); setMsg('Agendamento criado.'); await Promise.all([reloadCalendar(), reloadAvailability()]);
  }, 'Falha ao criar agendamento.');

  const changeApptStatus = (id, status, opts = {}) => run(async () => {
    await updateAppointmentStatus(Number(id), status, opts);
    setMsg('Status atualizado.'); await reloadCalendar();
  }, 'Falha ao atualizar status.');

  const cancelAppt = (id, customerName) => {
    if (!window.confirm(`Cancelar o agendamento de ${customerName || 'este cliente'}?\nO cliente será notificado por WhatsApp.`)) return;
    void changeApptStatus(id, 'cancelled', { notify_customer: true });
  };

  if (loading) {
    return (
      <Layout role="company" onLogout={logout}>
        <PageLoading rows={1} cards={2} />
      </Layout>
    );
  }
  if (error || !data?.authenticated) return <Layout><p className="text-sm text-red-600">Não foi possível carregar a agenda.</p></Layout>;
  if (!canManage) return <Layout role="company" companyName={data?.user?.company_name} onLogout={logout}><p className="text-sm text-[#64748b]">Acesso restrito ao time da empresa.</p></Layout>;

  return (
    <Layout role="company" companyName={data?.user?.company_name} onLogout={logout}>
      <section className="appointments-page">
        <header className="appointments-header">
          <h1 className="appointments-title">Agendamentos</h1>
          <p className="appointments-subtitle">Serviço único, atendentes, horários livres por dia e calendário semanal/mensal.</p>
          <div className="appointments-filters"><label>Atendente<select value={selectedStaffId} onChange={(e) => setSelectedStaffId(e.target.value)}><option value="">Todos</option>{staff.filter((s) => s.is_bookable).map((s) => <option key={s.id} value={String(s.id)}>{s.display_name || s.user_name}</option>)}</select></label></div>
          {busy && <p className="appointments-note">Atualizando...</p>}
          {msg && <p className="appointments-note appointments-note--ok">{msg}</p>}
          {errMsg && <p className="appointments-note appointments-note--error">{errMsg}</p>}
        </header>

        <div className="appointments-grid">
          <article className="appointments-card"><h2>Configurações</h2>{settings && <div className="appointments-form-grid">
            <label>Fuso<input value={settings.timezone || ''} onChange={(e) => setSettings((p) => ({ ...p, timezone: e.target.value }))} /></label>
            <label>Intervalo entre atendimentos (min)<input type="number" min="5" value={settings.slot_interval_minutes || 15} onChange={(e) => setSettings((p) => ({ ...p, slot_interval_minutes: Number(e.target.value) }))} /></label>
            <label>Antecedencia minima para agendar (tempo mínimo antes do horario)<DurationInput valueMinutes={settings.booking_min_notice_minutes || 0} onChange={(v) => setSettings((p) => ({ ...p, booking_min_notice_minutes: v }))} /></label>
            <label>Máximo em dias (quantos dias no futuro pode marcar)<input type="number" min="0" value={settings.booking_max_advance_days || 0} onChange={(e) => setSettings((p) => ({ ...p, booking_max_advance_days: Number(e.target.value) }))} /></label>
            <label>Antecedencia minima para cancelamento (0 = sem restricao)<DurationInput valueMinutes={settings.cancellation_min_notice_minutes || 0} onChange={(v) => setSettings((p) => ({ ...p, cancellation_min_notice_minutes: v }))} /></label>
          </div>}<button type="button" className="appointments-btn" onClick={saveSettings}>Salvar configurações</button></article>

          <article className="appointments-card"><h2>Serviço (único)</h2><p className="appointments-help">Duração = tempo de atendimento. O intervalo entre horários e configurado na jornada de cada dia.</p>
            <form onSubmit={saveService} className="appointments-form-grid">
              <label>Nome<input required value={serviceForm.name} onChange={(e) => setServiceForm((p) => ({ ...p, name: e.target.value }))} /></label>
              <label>Duração (min)<input required type="number" min="5" value={serviceForm.duration_minutes} onChange={(e) => setServiceForm((p) => ({ ...p, duration_minutes: Number(e.target.value) }))} /></label>
              <label className="appointments-checkbox"><input type="checkbox" checked={!!serviceForm.is_active} onChange={(e) => setServiceForm((p) => ({ ...p, is_active: e.target.checked }))} />Ativo</label>
              <button className="appointments-btn" type="submit">{service?.id ? 'Salvar serviço' : 'Criar serviço'}</button>
            </form>
            <p className="appointments-help">Para o bot mostrar "4 - Marcar agendamento", o serviço precisa estar ativo e deve existir ao menos um usuário marcado como atendente na tela de Usuários.</p>
          </article>

          <article className="appointments-card appointments-card--full"><h2>Jornada por atendente</h2>
            {selectedStaffId && <p className="appointments-help"><strong>{staff.find((s) => String(s.id) === selectedStaffId)?.display_name || staff.find((s) => String(s.id) === selectedStaffId)?.user_name || 'Atendente selecionado'}</strong> — use o filtro acima para trocar.</p>}
            {!selectedStaffId && <p className="appointments-help">Selecione um atendente no filtro acima para editar a jornada.</p>}
            <form onSubmit={saveHours}>
              <div className="appointments-hours-grid">
                {hours.map((h, i) => (
                  <div className="appointments-hour-row" key={h.day_of_week}>
                    <label className="appointments-hour-day">
                      <input
                        type="checkbox"
                        checked={!!h.is_active}
                        onChange={(e) =>
                          setHours((p) =>
                            p.map((x, idx) => (idx === i ? { ...x, is_active: e.target.checked } : x))
                          )
                        }
                      />
                      {DAYS[h.day_of_week]}
                    </label>

                    <label className="appointments-hour-field">
                      <span>Início</span>
                      <input
                        type="time"
                        disabled={!h.is_active}
                        value={h.start_time}
                        onChange={(e) =>
                          setHours((p) => p.map((x, idx) => (idx === i ? { ...x, start_time: e.target.value } : x)))
                        }
                      />
                    </label>

                    <label className="appointments-hour-field">
                      <span>Pausa início</span>
                      <input
                        type="time"
                        disabled={!h.is_active}
                        value={h.break_start_time || ''}
                        onChange={(e) =>
                          setHours((p) =>
                            p.map((x, idx) =>
                              idx === i ? { ...x, break_start_time: e.target.value } : x
                            )
                          )
                        }
                      />
                    </label>

                    <label className="appointments-hour-field">
                      <span>Pausa fim</span>
                      <input
                        type="time"
                        disabled={!h.is_active}
                        value={h.break_end_time || ''}
                        onChange={(e) =>
                          setHours((p) =>
                            p.map((x, idx) =>
                              idx === i ? { ...x, break_end_time: e.target.value } : x
                            )
                          )
                        }
                      />
                    </label>

                    <label className="appointments-hour-field">
                      <span>Fim</span>
                      <input
                        type="time"
                        disabled={!h.is_active}
                        value={h.end_time}
                        onChange={(e) =>
                          setHours((p) => p.map((x, idx) => (idx === i ? { ...x, end_time: e.target.value } : x)))
                        }
                      />
                    </label>
                  </div>
                ))}
              </div>
              <button className="appointments-btn" disabled={!selectedStaffId}>Salvar jornada</button>
            </form>
          </article>

          <article className="appointments-card"><h2>Horários livres</h2><div className="appointments-inline">
            <button className="appointments-btn appointments-btn--light" type="button" onClick={() => setAvailDate((p) => plusDays(p, -1))}>Dia anterior</button><strong>{fmtYmd(availDate)}</strong>
            <button className="appointments-btn appointments-btn--light" type="button" onClick={() => setAvailDate((p) => plusDays(p, 1))}>Próximo dia</button>
          </div>{!serviceId && <p className="appointments-empty">Crie o serviço para ver horários.</p>}
            <div className="appointments-availability-list">{availability.map((s) => <div className="appointments-availability-group" key={s.staff_profile_id}><h3>{s.staff_name}</h3>
              {s.slots?.length ? <div className="appointments-slots">{s.slots.map((slot) => <button key={`${s.staff_profile_id}-${slot.starts_at}`} type="button" className="appointments-slot-btn" onClick={() => { setSelectedStaffId(String(s.staff_profile_id)); setNewAppt((p) => ({ ...p, starts_at: toLocalInput(slot.starts_at_local || slot.starts_at) })); }}>{fmtHm(slot.starts_at_local || slot.starts_at)}</button>)}</div> : <p className="appointments-empty">Sem horários.</p>}
            </div>)}</div>
          </article>

          <article className="appointments-card">
            <h2>Novo agendamento</h2>
            <form onSubmit={addAppointment} className="appointments-form-grid">
              <label>
                Data e hora
                <input required type="datetime-local" value={newAppt.starts_at} onChange={(e) => setNewAppt((p) => ({ ...p, starts_at: e.target.value }))} />
                {apptFormWarning && <span className="appointments-field-warning">{apptFormWarning}</span>}
              </label>
              <label>Cliente<input value={newAppt.customer_name} onChange={(e) => setNewAppt((p) => ({ ...p, customer_name: e.target.value }))} /></label>
              <label>Telefone<input required value={newAppt.customer_phone} onChange={(e) => setNewAppt((p) => ({ ...p, customer_phone: e.target.value }))} /></label>
              <button className="appointments-btn" type="submit">Criar agendamento</button>
            </form>
          </article>

          <article className="appointments-card appointments-card--full"><h2>Bloqueio / folga</h2><form onSubmit={addTimeOff} className="appointments-form-grid">
            <label>Atendente<select value={newTimeOff.staff_profile_id} onChange={(e) => setNewTimeOff((p) => ({ ...p, staff_profile_id: e.target.value }))}><option value="">Todos</option>{staff.map((s) => <option key={s.id} value={String(s.id)}>{s.display_name || s.user_name}</option>)}</select></label>
            <label>Inicio<input required type="datetime-local" value={newTimeOff.starts_at} onChange={(e) => setNewTimeOff((p) => ({ ...p, starts_at: e.target.value }))} /></label>
            <label>Fim<input required type="datetime-local" value={newTimeOff.ends_at} onChange={(e) => setNewTimeOff((p) => ({ ...p, ends_at: e.target.value }))} /></label>
            <label>Motivo<input value={newTimeOff.reason} onChange={(e) => setNewTimeOff((p) => ({ ...p, reason: e.target.value }))} /></label>
            <button className="appointments-btn" type="submit">Registrar bloqueio</button></form>
          </article>

          <article className="appointments-card appointments-card--full"><h2>Calendário</h2><div className="appointments-calendar-toolbar">
            <div className="appointments-inline"><button type="button" className="appointments-btn appointments-btn--light" onClick={() => setAnchorDate((v) => calendarView === 'month' ? ymd(new Date(parseYmd(v).setMonth(parseYmd(v).getMonth() - 1))) : plusDays(v, -7))}>Anterior</button>
              <button type="button" className="appointments-btn appointments-btn--light" onClick={() => setAnchorDate(today)}>Hoje</button>
              <button type="button" className="appointments-btn appointments-btn--light" onClick={() => setAnchorDate((v) => calendarView === 'month' ? ymd(new Date(parseYmd(v).setMonth(parseYmd(v).getMonth() + 1))) : plusDays(v, 7))}>Próximo</button></div>
            <div className="appointments-inline"><button type="button" className={`appointments-btn appointments-btn--light ${calendarView === 'week' ? 'is-active' : ''}`} onClick={() => setCalendarView('week')}>Semana</button><button type="button" className={`appointments-btn appointments-btn--light ${calendarView === 'month' ? 'is-active' : ''}`} onClick={() => setCalendarView('month')}>Mês</button></div>
          </div>
            <div className="appointments-calendar-grid">{calDays.map((d) => <div key={d} className={`appointments-calendar-cell ${calendarView === 'month' && d.slice(0, 7) !== anchorDate.slice(0, 7) ? 'is-muted' : ''}`}>
              <div className="appointments-calendar-date">{fmtYmd(d)}</div>
              {(apptByDay[d] || []).length === 0 && !(timeOffs || []).some((t) => (t.starts_at || '').slice(0, 10) <= d && (t.ends_at || '').slice(0, 10) >= d)
                ? <p className="appointments-empty">Sem agendamentos</p>
                : <div className="appointments-calendar-events">
                    {(timeOffs || [])
                      .filter((t) => (t.starts_at || '').slice(0, 10) <= d && (t.ends_at || '').slice(0, 10) >= d)
                      .map((t) => <div key={`block-${t.id}-${d}`} className="appointments-calendar-block">Bloqueio: {t.staff_name || 'Todos'}{t.reason ? ` - ${t.reason}` : ''}</div>)}
                    {(apptByDay[d] || []).map((a) => (
                      <div key={a.id} className="appointments-calendar-event">
                        <div className="appointments-event-info">
                          <strong>{fmtHm(a.starts_at)}</strong> {a.customer_name || 'Cliente'} ({a.staff_name || 'Atendente'})
                          <span className={`appointments-status appointments-status--${a.status}`}>{STATUS_LABEL[a.status] || a.status}</span>
                        </div>
                        {!['cancelled', 'no_show'].includes(a.status) && (
                          <div className="appointments-event-actions">
                            {isPast(a.starts_at) ? (
                              <>
                                {a.status !== 'completed' && <button type="button" className="appointments-btn appointments-btn--xs" onClick={() => changeApptStatus(a.id, 'completed')}>Compareceu</button>}
                                {a.status !== 'no_show' && <button type="button" className="appointments-btn appointments-btn--xs appointments-btn--danger" onClick={() => changeApptStatus(a.id, 'no_show')}>Não compareceu</button>}
                              </>
                            ) : (
                              <>
                                {a.status !== 'confirmed' && <button type="button" className="appointments-btn appointments-btn--xs" onClick={() => changeApptStatus(a.id, 'confirmed')}>Confirmar</button>}
                                <button type="button" className="appointments-btn appointments-btn--xs appointments-btn--danger" onClick={() => cancelAppt(a.id, a.customer_name)}>Cancelar</button>
                              </>
                            )}
                          </div>
                        )}
                      </div>
                    ))}
                  </div>}
            </div>)}</div>
          </article>

          <article className="appointments-card appointments-card--full"><h2>Agendamentos do periodo</h2>
            {!appointments.length ? <p className="appointments-empty">Nenhum agendamento.</p> : (
              <ul className="appointments-block-list">
                {appointments.map((a) => (
                  <li key={a.id}>
                    <div>
                      <strong>{a.customer_name || 'Cliente'}</strong>
                      <span className={`appointments-status appointments-status--${a.status}`}>{STATUS_LABEL[a.status] || a.status}</span>
                      <p>{fmtDt(a.starts_at)} · {a.staff_name || 'Atendente'}</p>
                      {a.customer_phone && <p>{a.customer_phone}</p>}
                    </div>
                    <div className="appointments-event-actions">
                      {!['cancelled', 'no_show'].includes(a.status) && isPast(a.starts_at) ? (
                        <>
                          {a.status !== 'completed' && <button type="button" className="appointments-btn appointments-btn--xs" onClick={() => changeApptStatus(a.id, 'completed')}>Compareceu</button>}
                          <button type="button" className="appointments-btn appointments-btn--xs appointments-btn--danger" onClick={() => changeApptStatus(a.id, 'no_show')}>Não compareceu</button>
                        </>
                      ) : !['cancelled', 'completed', 'no_show'].includes(a.status) ? (
                        <>
                          {a.status === 'pending' && <button type="button" className="appointments-btn appointments-btn--xs" onClick={() => changeApptStatus(a.id, 'confirmed')}>Confirmar</button>}
                          <button type="button" className="appointments-btn appointments-btn--xs appointments-btn--danger" onClick={() => cancelAppt(a.id, a.customer_name)}>Cancelar</button>
                        </>
                      ) : null}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </article>

          <article className="appointments-card appointments-card--full"><h2>Bloqueios do periodo</h2>
            {!timeOffs.length ? <p className="appointments-empty">Nenhum bloqueio.</p> : <ul className="appointments-block-list">{timeOffs.map((t) => <li key={t.id}><div><strong>{t.staff_name || 'Todos os atendentes'}</strong><p>{fmtDt(t.starts_at)} até {fmtDt(t.ends_at)}</p><p>{t.reason || 'Sem motivo informado'}</p></div><button type="button" className="appointments-btn appointments-btn--danger" onClick={() => void removeTimeOff(t.id)}>Remover</button></li>)}</ul>}
          </article>
        </div>
      </section>
    </Layout>
  );
}
