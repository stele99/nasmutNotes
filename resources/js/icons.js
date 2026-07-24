import {
  Bold,
  Check,
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
  Menu,
  MoreHorizontal,
  Pencil,
  Plus,
  Quote,
  Redo2,
  Search,
  Star,
  Trash2,
  Underline,
  Undo2,
} from 'lucide';

const icons = {
  bold: Bold,
  check: Check,
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
  menu: Menu,
  'more-horizontal': MoreHorizontal,
  pencil: Pencil,
  plus: Plus,
  quote: Quote,
  redo: Redo2,
  search: Search,
  star: Star,
  trash: Trash2,
  underline: Underline,
  undo: Undo2,
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
