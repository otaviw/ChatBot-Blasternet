import { useCallback, useEffect, useReducer, useRef } from 'react';

const SET_ATTACHMENT = 'SET_ATTACHMENT';
const SET_UPLOAD_PROGRESS = 'SET_UPLOAD_PROGRESS';
const SET_UPLOADING = 'SET_UPLOADING';
const RESET_ATTACHMENT = 'RESET_ATTACHMENT';

const INITIAL_ATTACHMENT_STATE = {
  file: null,
  previewUrl: '',
  progress: 0,
  uploading: false,
};

function attachmentUploaderReducer(state, action) {
  switch (action.type) {
    case SET_ATTACHMENT:
      return {
        ...state,
        file: action.payload?.file ?? null,
        previewUrl: String(action.payload?.previewUrl ?? ''),
        progress: 0,
      };
    case SET_UPLOAD_PROGRESS:
      return {
        ...state,
        progress: Number(action.payload ?? 0),
      };
    case SET_UPLOADING:
      return {
        ...state,
        uploading: Boolean(action.payload),
      };
    case RESET_ATTACHMENT:
      return {
        ...INITIAL_ATTACHMENT_STATE,
      };
    default:
      return state;
  }
}

export default function useAttachmentUploader() {
  const [state, dispatch] = useReducer(attachmentUploaderReducer, INITIAL_ATTACHMENT_STATE);
  const abortControllerRef = useRef(null);

  const revokePreview = useCallback((url) => {
    if (url) {
      URL.revokeObjectURL(url);
    }
  }, []);

  useEffect(() => (
    () => {
      if (state.previewUrl) {
        URL.revokeObjectURL(state.previewUrl);
      }
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
        abortControllerRef.current = null;
      }
    }
  ), [state.previewUrl]);

  const setAttachmentFile = useCallback((file) => {
    revokePreview(state.previewUrl);
    if (!file) {
      dispatch({ type: RESET_ATTACHMENT });
      return;
    }

    dispatch({
      type: SET_ATTACHMENT,
      payload: {
        file,
        previewUrl: URL.createObjectURL(file),
      },
    });
  }, [revokePreview, state.previewUrl]);

  const handleAttachmentChange = useCallback((event) => {
    const file = event?.target?.files?.[0] ?? null;
    if (!file) {
      return null;
    }
    setAttachmentFile(file);
    return file;
  }, [setAttachmentFile]);

  const clearAttachment = useCallback(() => {
    revokePreview(state.previewUrl);
    dispatch({ type: RESET_ATTACHMENT });
  }, [revokePreview, state.previewUrl]);

  const startUpload = useCallback(() => {
    if (!state.file) {
      return {};
    }

    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
    }

    const controller = new AbortController();
    abortControllerRef.current = controller;
    dispatch({ type: SET_UPLOADING, payload: true });
    dispatch({ type: SET_UPLOAD_PROGRESS, payload: 0 });

    return {
      signal: controller.signal,
      onUploadProgress: (event) => {
        const total = Number(event?.total ?? 0);
        const loaded = Number(event?.loaded ?? 0);
        if (total > 0) {
          const percentage = Math.max(0, Math.min(100, Math.round((loaded / total) * 100)));
          dispatch({ type: SET_UPLOAD_PROGRESS, payload: percentage });
        }
      },
    };
  }, [state.file]);

  const finishUpload = useCallback(() => {
    abortControllerRef.current = null;
    dispatch({ type: SET_UPLOADING, payload: false });
    dispatch({ type: SET_UPLOAD_PROGRESS, payload: 0 });
  }, []);

  const cancelUpload = useCallback(() => {
    if (abortControllerRef.current) {
      abortControllerRef.current.abort();
      abortControllerRef.current = null;
    }
    dispatch({ type: SET_UPLOADING, payload: false });
  }, []);

  return {
    file: state.file,
    previewUrl: state.previewUrl,
    progress: state.progress,
    uploading: state.uploading,
    setAttachmentFile,
    handleAttachmentChange,
    clearAttachment,
    startUpload,
    finishUpload,
    cancelUpload,
  };
}
