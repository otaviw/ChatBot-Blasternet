import { useCallback, useEffect, useMemo, useReducer } from 'react';
import { CONVERSATION_ASSIGNED_TYPE } from '@/constants/conversation';

const SET_SHOW_TEMPLATES = 'SET_SHOW_TEMPLATES';
const SET_TRANSFER_AREA = 'SET_TRANSFER_AREA';
const SET_TRANSFER_USER_ID = 'SET_TRANSFER_USER_ID';
const RESET_SEARCH_FILTERS = 'RESET_SEARCH_FILTERS';

const INITIAL_SEARCH_STATE = {
  showTemplates: false,
  transferArea: '',
  transferUserId: '',
};

const resolveNextValue = (previous, nextOrUpdater) => (
  typeof nextOrUpdater === 'function' ? nextOrUpdater(previous) : nextOrUpdater
);

function searchReducer(state, action) {
  switch (action.type) {
    case SET_SHOW_TEMPLATES:
      return {
        ...state,
        showTemplates: Boolean(resolveNextValue(state.showTemplates, action.payload)),
      };
    case SET_TRANSFER_AREA:
      return {
        ...state,
        transferArea: String(resolveNextValue(state.transferArea, action.payload) ?? ''),
      };
    case SET_TRANSFER_USER_ID:
      return {
        ...state,
        transferUserId: String(resolveNextValue(state.transferUserId, action.payload) ?? ''),
      };
    case RESET_SEARCH_FILTERS:
      return {
        ...state,
        transferArea: '',
        transferUserId: '',
      };
    default:
      return state;
  }
}

export default function useConversationSearch({
  detail,
  transferOptionsUsers,
  onTransferStateReset,
}) {
  const [searchState, searchDispatch] = useReducer(searchReducer, INITIAL_SEARCH_STATE);

  const setShowTemplates = useCallback((valueOrUpdater) => {
    searchDispatch({ type: SET_SHOW_TEMPLATES, payload: valueOrUpdater });
  }, []);

  const setTransferArea = useCallback((valueOrUpdater) => {
    searchDispatch({ type: SET_TRANSFER_AREA, payload: valueOrUpdater });
  }, []);

  const setTransferUserId = useCallback((valueOrUpdater) => {
    searchDispatch({ type: SET_TRANSFER_USER_ID, payload: valueOrUpdater });
  }, []);

  const resetSearchFilters = useCallback(() => {
    searchDispatch({ type: RESET_SEARCH_FILTERS });
  }, []);

  useEffect(() => {
    if (!detail?.id) {
      return;
    }

    setTransferArea(
      detail.assigned_type === CONVERSATION_ASSIGNED_TYPE.AREA ? String(detail.assigned_id ?? '') : ''
    );
  }, [detail?.assigned_id, detail?.assigned_type, detail?.id, setTransferArea]);

  const availableUsers = useMemo(() => {
    const users = transferOptionsUsers ?? [];
    if (!searchState.transferArea) {
      return users;
    }

    return users.filter((user) =>
      (user.areas ?? []).some((area) => String(area.id) === String(searchState.transferArea))
    );
  }, [searchState.transferArea, transferOptionsUsers]);

  useEffect(() => {
    if (!searchState.transferUserId) {
      return;
    }

    const exists = availableUsers.some(
      (user) => String(user.id) === String(searchState.transferUserId)
    );
    if (!exists) {
      setTransferUserId('');
    }
  }, [availableUsers, searchState.transferUserId, setTransferUserId]);

  const handleTransferAreaChange = useCallback((value) => {
    setTransferArea(value);
    onTransferStateReset?.();
  }, [onTransferStateReset, setTransferArea]);

  const handleTransferUserChange = useCallback((value) => {
    setTransferUserId(value);
    onTransferStateReset?.();

    if (value) {
      const selectedUser = (transferOptionsUsers ?? []).find(
        (user) => String(user.id) === String(value)
      );
      if (selectedUser?.areas?.length && !searchState.transferArea) {
        setTransferArea(String(selectedUser.areas[0].id));
      }
    }
  }, [onTransferStateReset, searchState.transferArea, setTransferArea, setTransferUserId, transferOptionsUsers]);

  return {
    showTemplates: searchState.showTemplates,
    transferArea: searchState.transferArea,
    transferUserId: searchState.transferUserId,
    availableUsers,
    setShowTemplates,
    setTransferArea,
    setTransferUserId,
    resetSearchFilters,
    handleTransferAreaChange,
    handleTransferUserChange,
  };
}
