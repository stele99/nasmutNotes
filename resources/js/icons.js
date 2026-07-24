import {
  Bold,
  Check,
  ClipboardPaste,
  ChevronRight,
  Code2,
  FileText,
  FolderKanban,
  Heading1,
  Heading2,
  Home,
  Italic,
  Link,
  List,
  ListTodo,
  LogOut,
  Menu,
  MoreHorizontal,
  Pencil,
  Plus,
  Quote,
  Redo2,
  Search,
  Share2,
  Star,
  Trash2,
  Underline,
  Undo2,
  X,
} from 'lucide';

const icons = {
  bold: Bold,
  check: Check,
  'clipboard-paste': ClipboardPaste,
  'chevron-right': ChevronRight,
  code: Code2,
  'file-text': FileText,
  'folder-kanban': FolderKanban,
  'heading-1': Heading1,
  'heading-2': Heading2,
  home: Home,
  italic: Italic,
  link: Link,
  list: List,
  'list-todo': ListTodo,
  'log-out': LogOut,
  menu: Menu,
  'more-horizontal': MoreHorizontal,
  pencil: Pencil,
  plus: Plus,
  quote: Quote,
  redo: Redo2,
  search: Search,
  'share-2': Share2,
  star: Star,
  trash: Trash2,
  underline: Underline,
  undo: Undo2,
  x: X,
};

function renderNode([tag, attributes, children]) {
  const attrs = Object.entries(attributes)
    .map(([key, value]) => `${key}="${value}"`)
    .join(' ');
  const content = (children || []).map(renderNode).join('');

  return `<${tag} ${attrs}>${content}</${tag}>`;
}

export function icon(name, className = 'size-4') {
  const iconNode = icons[name] || FileText;
  const [tag, attributes, children] = iconNode;

  return renderNode([
    tag,
    { ...attributes, class: className, 'aria-hidden': 'true' },
    children,
  ]);
}

export function renderIconDirective(el, expression) {
  const name = expression.replace(/["']/g, '').trim();
  el.innerHTML = icon(name);
}
