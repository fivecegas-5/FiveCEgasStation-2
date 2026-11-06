import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App.jsx';

const appRoot = document.getElementById('react-app-root');
if (appRoot) {
    createRoot(appRoot).render(<App root={appRoot} />);
}
