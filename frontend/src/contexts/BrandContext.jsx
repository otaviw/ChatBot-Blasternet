import { useEffect, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import api from '@/services/api';
import { getSlugFromUrl } from '@/utils/urlSlug';
import { BrandContext, DEFAULT_BRANDING } from './brandContextObject';

function readOptionalString(value) {
  const normalized = String(value ?? '').trim();
  return normalized || null;
}

function normalizeHexColor(value) {
  const color = readOptionalString(value);
  if (!color) {
    return null;
  }

  if (!/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(color)) {
    return null;
  }

  return color.toLowerCase();
}

function hexToRgb(color) {
  const normalized = normalizeHexColor(color);
  if (!normalized) {
    return null;
  }

  const hex = normalized.length === 4
    ? normalized
      .slice(1)
      .split('')
      .map((char) => char + char)
      .join('')
    : normalized.slice(1);

  const r = Number.parseInt(hex.slice(0, 2), 16);
  const g = Number.parseInt(hex.slice(2, 4), 16);
  const b = Number.parseInt(hex.slice(4, 6), 16);

  return { r, g, b };
}

function rgbaFromHex(color, alpha) {
  const rgb = hexToRgb(color);
  if (!rgb) {
    return null;
  }

  return `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${alpha})`;
}

function darkenHex(color, factor = 0.16) {
  const rgb = hexToRgb(color);
  if (!rgb) {
    return null;
  }

  const clamp = (value) => Math.max(0, Math.min(255, Math.round(value)));
  const r = clamp(rgb.r * (1 - factor));
  const g = clamp(rgb.g * (1 - factor));
  const b = clamp(rgb.b * (1 - factor));

  return `#${[r, g, b].map((channel) => channel.toString(16).padStart(2, '0')).join('')}`;
}

function normalizeBranding(payload) {
  const primaryColor = normalizeHexColor(payload?.primary_color);

  return {
    name: readOptionalString(payload?.name) ?? DEFAULT_BRANDING.name,
    logo: readOptionalString(payload?.logo ?? payload?.logo_url),
    primary_color: primaryColor,
  };
}

function applyPrimaryColor(branding) {
  if (typeof document === 'undefined') {
    return;
  }

  const color = normalizeHexColor(branding?.primary_color);
  const hoverColor = darkenHex(color);
  const mutedColor = rgbaFromHex(color, 0.14);
  const mutedStrongColor = rgbaFromHex(color, 0.2);
  const softBorderColor = rgbaFromHex(color, 0.36);
  const ringColor = rgbaFromHex(color, 0.26);
  const darkRingColor = rgbaFromHex(color, 0.3);

  if (color) {
    document.documentElement.style.setProperty('--primary-color', color);
    document.documentElement.style.setProperty('--primary-color-dark', color);
    if (hoverColor) {
      document.documentElement.style.setProperty('--primary-color-hover', hoverColor);
      document.documentElement.style.setProperty('--primary-color-dark-hover', hoverColor);
    }
    if (mutedColor) {
      document.documentElement.style.setProperty('--primary-color-soft', mutedColor);
      document.documentElement.style.setProperty('--primary-color-dark-soft', mutedColor);
    }
    if (mutedStrongColor) {
      document.documentElement.style.setProperty('--primary-color-soft-strong', mutedStrongColor);
      document.documentElement.style.setProperty('--primary-color-dark-soft-strong', mutedStrongColor);
    }
    if (softBorderColor) {
      document.documentElement.style.setProperty('--primary-color-soft-border', softBorderColor);
      document.documentElement.style.setProperty('--primary-color-dark-soft-border', softBorderColor);
    }
    if (ringColor) {
      document.documentElement.style.setProperty('--primary-color-ring', ringColor);
    }
    if (darkRingColor) {
      document.documentElement.style.setProperty('--primary-color-dark-ring', darkRingColor);
    }
    return;
  }

  document.documentElement.style.removeProperty('--primary-color');
  document.documentElement.style.removeProperty('--primary-color-hover');
  document.documentElement.style.removeProperty('--primary-color-soft');
  document.documentElement.style.removeProperty('--primary-color-soft-strong');
  document.documentElement.style.removeProperty('--primary-color-soft-border');
  document.documentElement.style.removeProperty('--primary-color-ring');
  document.documentElement.style.removeProperty('--primary-color-dark');
  document.documentElement.style.removeProperty('--primary-color-dark-hover');
  document.documentElement.style.removeProperty('--primary-color-dark-soft');
  document.documentElement.style.removeProperty('--primary-color-dark-soft-strong');
  document.documentElement.style.removeProperty('--primary-color-dark-soft-border');
  document.documentElement.style.removeProperty('--primary-color-dark-ring');
}

export function BrandProvider({ children }) {
  const location = useLocation();
  const [branding, setBranding] = useState(DEFAULT_BRANDING);

  useEffect(() => {
    let canceled = false;
    const slug = getSlugFromUrl(location.pathname);
    const endpoint = slug
      ? `/branding?slug=${encodeURIComponent(slug)}`
      : '/branding';

    api
      .get(endpoint, { skipAuthRedirect: true })
      .then((response) => {
        if (canceled) return;
        const normalizedBranding = normalizeBranding(response?.data);
        setBranding(normalizedBranding);
        applyPrimaryColor(normalizedBranding);
      })
      .catch(() => {
        if (canceled) return;
        setBranding(DEFAULT_BRANDING);
        applyPrimaryColor(DEFAULT_BRANDING);
      });

    return () => {
      canceled = true;
    };
  }, [location.pathname]);

  const value = useMemo(() => ({ branding }), [branding]);
  return <BrandContext.Provider value={value}>{children}</BrandContext.Provider>;
}
