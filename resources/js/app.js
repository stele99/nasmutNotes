import Alpine from '@alpinejs/csp';
import '../css/app.css';

import { theme } from './theme.js';
import { pageList } from './pageList.js';
import { taskBoard } from './taskBoard.js';
import { noteEditorPage } from './notePage.js';
import { logPage } from './logPage.js';
import { pageShare } from './pageShare.js';
import { adminInvites } from './adminInvites.js';
import { adminDashboard } from './adminDashboard.js';
import { adminBackups } from './adminBackups.js';
import { userInvites } from './userInvites.js';
import { trashPanel } from './trashPanel.js';
import { noteImport } from './noteImport.js';
import { noteExport } from './noteExport.js';
import { workspaceShell } from './workspaceShell.js';
import { offlineSettings } from './offline/settings.js';
import { initOfflineRuntime } from './offline/runtime.js';
import { renderIconDirective } from './icons.js';
import { initViewportMetrics } from './viewport.js';

Alpine.data('theme', theme);
Alpine.data('pageList', pageList);
Alpine.data('taskBoard', taskBoard);
Alpine.data('noteEditorPage', noteEditorPage);
Alpine.data('logPage', logPage);
Alpine.data('pageShare', pageShare);
Alpine.data('adminInvites', adminInvites);
Alpine.data('adminDashboard', adminDashboard);
Alpine.data('adminBackups', adminBackups);
Alpine.data('userInvites', userInvites);
Alpine.data('trashPanel', trashPanel);
Alpine.data('noteImport', noteImport);
Alpine.data('noteExport', noteExport);
Alpine.data('workspaceShell', workspaceShell);
Alpine.data('offlineSettings', offlineSettings);
Alpine.directive('icon', (el, { expression }) => renderIconDirective(el, expression));

window.Alpine = Alpine;

// Vor Alpine.start(), damit die Maße schon beim ersten Aufbau der Oberfläche
// stehen und kein Layoutsprung entsteht.
initViewportMetrics();

// Alpine startet unabhängig vom Offline-Init: hängt IndexedDB (blockiertes
// Upgrade, Privatmodus), darf die App davon nicht ausgebremst werden. Die
// Offline-Komponenten lesen ihren Zustand ohnehin über onStatusChange nach.
Alpine.start();
void initOfflineRuntime().catch(() => {
  /* Offline-Funktionen sind optional. */
});
