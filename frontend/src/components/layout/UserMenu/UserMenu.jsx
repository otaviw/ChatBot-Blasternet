import { useEffect, useRef, useState } from 'react';
import api from '@/services/api';
import ThemeSwitcher from '@/components/layout/ThemeSwitcher/ThemeSwitcher.jsx';

const ICON_PROFILE = (
  <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" />
    <circle cx="12" cy="7" r="4" />
  </svg>
);

export default function UserMenu({
  role,
  companyName,
  userData,
  onUserDataChange,
  onLogout,
  themeMode,
  onToggleTheme,
}) {
  const profileRef = useRef(null);
  const [profileOpen, setProfileOpen] = useState(false);
  const [profileEditName, setProfileEditName] = useState(false);
  const [profileName, setProfileName] = useState('');
  const [profileSaveLoading, setProfileSaveLoading] = useState(false);
  const [profileSaveError, setProfileSaveError] = useState('');
  const [profileEditPassword, setProfileEditPassword] = useState(false);
  const [passwordCurrent, setPasswordCurrent] = useState('');
  const [passwordNew, setPasswordNew] = useState('');
  const [passwordConfirm, setPasswordConfirm] = useState('');
  const [passwordSaveLoading, setPasswordSaveLoading] = useState(false);
  const [passwordSaveError, setPasswordSaveError] = useState('');
  const [passwordSaveSuccess, setPasswordSaveSuccess] = useState('');

  useEffect(() => {
    const handleClickOutside = (event) => {
      if (profileRef.current && !profileRef.current.contains(event.target)) {
        setProfileOpen(false);
      }
    };

    if (profileOpen) {
      document.addEventListener('click', handleClickOutside);
    }

    return () => document.removeEventListener('click', handleClickOutside);
  }, [profileOpen]);

  const resetPasswordForm = () => {
    setProfileEditPassword(false);
    setPasswordCurrent('');
    setPasswordNew('');
    setPasswordConfirm('');
    setPasswordSaveError('');
    setPasswordSaveSuccess('');
  };

  const handleSaveName = async (event) => {
    event.preventDefault();
    const name = String(profileName ?? '').trim();
    if (!name) {
      return;
    }

    setProfileSaveLoading(true);
    setProfileSaveError('');

    try {
      const res = await api.patch('/me', { name });
      onUserDataChange?.(res.data?.user ?? userData);
      setProfileEditName(false);
      setProfileName('');
    } catch (error) {
      setProfileSaveError(error.response?.data?.message ?? 'Erro ao salvar.');
    } finally {
      setProfileSaveLoading(false);
    }
  };

  const handleSavePassword = async (event) => {
    event.preventDefault();
    setPasswordSaveError('');
    setPasswordSaveSuccess('');

    if (!passwordCurrent.trim()) {
      setPasswordSaveError('Informe a senha atual.');
      return;
    }
    if (passwordNew.length < 6) {
      setPasswordSaveError('A nova senha deve ter pelo menos 6 caracteres.');
      return;
    }
    if (passwordNew !== passwordConfirm) {
      setPasswordSaveError('A confirmação da nova senha não confere.');
      return;
    }

    setPasswordSaveLoading(true);
    try {
      await api.put('/me/password', {
        current_password: passwordCurrent,
        password: passwordNew,
        password_confirmation: passwordConfirm,
      });
      setPasswordSaveSuccess('Senha alterada com sucesso.');
      setPasswordCurrent('');
      setPasswordNew('');
      setPasswordConfirm('');
    } catch (error) {
      setPasswordSaveError(error.response?.data?.message ?? 'Erro ao alterar senha.');
    } finally {
      setPasswordSaveLoading(false);
    }
  };

  const handleLogoutClick = (event) => {
    event.preventDefault();
    setProfileOpen(false);
    onLogout?.();
  };

  return (
    <div className="layout-profile" ref={profileRef}>
      <button
        type="button"
        className="layout-header__btn layout-header__btn--profile"
        onClick={(event) => { event.stopPropagation(); setProfileOpen((value) => !value); }}
        title="Perfil"
        aria-label="Abrir menu de perfil"
      >
        {ICON_PROFILE}
        <span className="layout-header__btn-label">Perfil</span>
      </button>
      {profileOpen && (
        <div className="layout-profile__dropdown" onClick={(event) => event.stopPropagation()}>
          <div className="layout-profile__info">
            <span className="layout-profile__name">{userData?.name ?? 'Usuário'}</span>
            <span className="layout-profile__email">{userData?.email ?? ''}</span>
            {role === 'company' && companyName && (
              <span className="layout-profile__company">{companyName}</span>
            )}
          </div>
          {profileEditName ? (
            <form onSubmit={handleSaveName} className="layout-profile__edit">
              <input
                type="text"
                value={profileName}
                onChange={(event) => setProfileName(event.target.value)}
                placeholder="Nome"
                className="layout-profile__input"
                autoFocus
              />
              {profileSaveError && <p className="layout-profile__error">{profileSaveError}</p>}
              <div className="layout-profile__edit-actions">
                <button type="submit" className="layout-profile__btn layout-profile__btn--primary" disabled={profileSaveLoading}>
                  Salvar
                </button>
                <button type="button" className="layout-profile__btn" onClick={() => { setProfileEditName(false); setProfileName(''); setProfileSaveError(''); }}>
                  Cancelar
                </button>
              </div>
            </form>
          ) : (
            <button
              type="button"
              className="layout-profile__item"
              onClick={() => { setProfileEditName(true); setProfileName(userData?.name ?? ''); resetPasswordForm(); }}
            >
              Gerenciar nome
            </button>
          )}
          {profileEditPassword ? (
            <form onSubmit={handleSavePassword} className="layout-profile__edit">
              <input
                type="password"
                value={passwordCurrent}
                onChange={(event) => { setPasswordCurrent(event.target.value); setPasswordSaveError(''); setPasswordSaveSuccess(''); }}
                placeholder="Senha atual"
                className="layout-profile__input"
                autoComplete="current-password"
                autoFocus
              />
              <input
                type="password"
                value={passwordNew}
                onChange={(event) => { setPasswordNew(event.target.value); setPasswordSaveError(''); setPasswordSaveSuccess(''); }}
                placeholder="Nova senha (mín. 6 caracteres)"
                className="layout-profile__input"
                autoComplete="new-password"
              />
              <input
                type="password"
                value={passwordConfirm}
                onChange={(event) => { setPasswordConfirm(event.target.value); setPasswordSaveError(''); setPasswordSaveSuccess(''); }}
                placeholder="Confirmar nova senha"
                className="layout-profile__input"
                autoComplete="new-password"
              />
              {passwordSaveError && <p className="layout-profile__error">{passwordSaveError}</p>}
              {passwordSaveSuccess && <p className="layout-profile__success">{passwordSaveSuccess}</p>}
              <div className="layout-profile__edit-actions">
                <button type="submit" className="layout-profile__btn layout-profile__btn--primary" disabled={passwordSaveLoading}>
                  {passwordSaveLoading ? 'Salvando...' : 'Alterar senha'}
                </button>
                <button type="button" className="layout-profile__btn" onClick={resetPasswordForm}>
                  Cancelar
                </button>
              </div>
            </form>
          ) : (
            <button
              type="button"
              className="layout-profile__item"
              onClick={() => { setProfileEditPassword(true); setProfileEditName(false); setProfileName(''); setProfileSaveError(''); }}
            >
              Alterar senha
            </button>
          )}
          <ThemeSwitcher themeMode={themeMode} onToggle={onToggleTheme} />
          <button
            type="button"
            className="layout-profile__item layout-profile__item--logout"
            onClick={handleLogoutClick}
          >
            Sair
          </button>
        </div>
      )}
    </div>
  );
}
