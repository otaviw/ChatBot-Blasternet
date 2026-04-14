// @vitest-environment jsdom
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { restoreOriginalFavicon, updateFaviconBadge } from './faviconBadge';

describe('faviconBadge', () => {
  let ctx;
  let imageOnLoad;

  beforeEach(() => {
    document.head.innerHTML = '<link rel="icon" type="image/x-icon" href="/favicon.ico" />';
    document.title = 'ChatBot-Blasternet';

    ctx = {
      drawImage: vi.fn(),
      fillRect: vi.fn(),
      beginPath: vi.fn(),
      arc: vi.fn(),
      fill: vi.fn(),
      fillText: vi.fn(),
      set fillStyle(_value) {},
      set font(_value) {},
      set textAlign(_value) {},
      set textBaseline(_value) {},
    };

    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(ctx);
    vi.spyOn(HTMLCanvasElement.prototype, 'toDataURL').mockReturnValue('data:image/png;base64,mocked');

    class ImageMock {
      set onload(callback) {
        this._onload = callback;
      }

      set onerror(callback) {
        this._onerror = callback;
      }

      set src(_value) {
        imageOnLoad = this._onload;
        queueMicrotask(() => {
          if (imageOnLoad) {
            imageOnLoad();
          }
        });
      }
    }

    vi.stubGlobal('Image', ImageMock);
  });

  it('atualiza o favicon com badge quando count > 0', async () => {
    await updateFaviconBadge(3);

    const link = document.querySelector('link[rel~="icon"]');
    expect(link).not.toBeNull();
    expect(link?.getAttribute('href')).toBe('data:image/png;base64,mocked');
    expect(link?.getAttribute('type')).toBe('image/png');
  });

  it('usa label 99+ quando count > 99', async () => {
    await updateFaviconBadge(120);
    expect(ctx.fillText).toHaveBeenCalledWith('99+', expect.any(Number), expect.any(Number));
  });

  it('restaura o favicon original quando count volta para zero', async () => {
    await updateFaviconBadge(5);
    await restoreOriginalFavicon();

    const link = document.querySelector('link[rel~="icon"]');
    expect(link?.getAttribute('href')).toBe('/favicon.ico');
    expect(link?.getAttribute('type')).toBe('image/x-icon');
  });
});

