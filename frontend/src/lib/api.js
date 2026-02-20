import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE ?? '/api',
  withCredentials: true,
  headers: { Accept: 'application/json' },
});

export default api;
