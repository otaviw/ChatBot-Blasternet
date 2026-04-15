const DEFAULT_TITLE = 'Blasternet ChatBot';
const BADGE_SIZE = 32;

let originalHref = null;
let originalType = null;

function ensureFaviconLink() {
  let link = document.querySelector('link[rel~="icon"]');
  if (!link) {
    link = document.createElement('link');
    link.setAttribute('rel', 'icon');
    link.setAttribute('href', '/favicon.ico');
    document.head.appendChild(link);
  }

  if (originalHref === null) {
    originalHref = link.getAttribute('href') || '/favicon.ico';
    originalType = link.getAttribute('type') || 'image/x-icon';
  }

  return link;
}

function loadImage(src) {
  return new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error('Falha ao carregar favicon original.'));
    image.crossOrigin = 'anonymous';
    image.src = src;
  });
}

function badgeLabel(count) {
  if (count > 99) {
    return '99+';
  }

  return String(count);
}

function drawBadge(canvas, label) {
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    return;
  }

  const radius = 10;
  const centerX = BADGE_SIZE - 8;
  const centerY = 8;

  ctx.fillStyle = '#dc2626';
  ctx.beginPath();
  ctx.arc(centerX, centerY, radius, 0, Math.PI * 2);
  ctx.fill();

  ctx.fillStyle = '#ffffff';
  ctx.font = label.length > 2 ? 'bold 9px sans-serif' : 'bold 10px sans-serif';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(label, centerX, centerY + 0.5);
}

export async function updateFaviconBadge(count) {
  const safeCount = Number.isFinite(Number(count)) ? Math.max(0, Number(count)) : 0;
  const link = ensureFaviconLink();

  if (safeCount === 0) {
    link.setAttribute('href', originalHref || '/favicon.ico');
    if (originalType) {
      link.setAttribute('type', originalType);
    }
    return;
  }

  const originalSrc = originalHref || link.getAttribute('href') || '/favicon.ico';
  const canvas = document.createElement('canvas');
  canvas.width = BADGE_SIZE;
  canvas.height = BADGE_SIZE;
  const ctx = canvas.getContext('2d');

  if (!ctx) {
    return;
  }

  try {
    const image = await loadImage(originalSrc);
    ctx.drawImage(image, 0, 0, BADGE_SIZE, BADGE_SIZE);
  } catch (_error) {
    ctx.fillStyle = '#111827';
    ctx.fillRect(0, 0, BADGE_SIZE, BADGE_SIZE);
  }

  drawBadge(canvas, badgeLabel(safeCount));
  link.setAttribute('href', canvas.toDataURL('image/png'));
  link.setAttribute('type', 'image/png');
}

export async function restoreOriginalFavicon() {
  await updateFaviconBadge(0);
}

export function formatDocumentTitle(count) {
  const safeCount = Number.isFinite(Number(count)) ? Math.max(0, Number(count)) : 0;
  if (safeCount <= 0) {
    return DEFAULT_TITLE;
  }

  return `(${safeCount}) ${DEFAULT_TITLE}`;
}

