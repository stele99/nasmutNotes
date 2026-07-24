import Alpine from '@alpinejs/csp';
import '../css/app.css';

import { theme } from './theme.js';
import { pageList } from './pageList.js';
import { taskBoard } from './taskBoard.js';
import { noteEditorPage } from './notePage.js';
import { pageShare } from './pageShare.js';
import { adminInvites } from './adminInvites.js';
import { workspaceShell } from './workspaceShell.js';
import { renderIconDirective } from './icons.js';

Alpine.data('theme', theme);
Alpine.data('pageList', pageList);
Alpine.data('taskBoard', taskBoard);
Alpine.data('noteEditorPage', noteEditorPage);
Alpine.data('pageShare', pageShare);
Alpine.data('adminInvites', adminInvites);
Alpine.data('workspaceShell', workspaceShell);
Alpine.directive('icon', (el, { expression }) => renderIconDirective(el, expression));

window.Alpine = Alpine;
Alpine.start();
