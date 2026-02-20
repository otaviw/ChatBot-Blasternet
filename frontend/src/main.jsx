import React from 'react';
import ReactDOM from 'react-dom/client';
import './app.css';
import AppRouter from './AppRouter';

const rootElement = document.getElementById('root');

if (rootElement) {
  ReactDOM.createRoot(rootElement).render(
    <React.StrictMode>
      <AppRouter />
    </React.StrictMode>
  );
}
