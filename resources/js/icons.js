import {
  BetweenHorizontalStart,
  BetweenVerticalStart,
  BookOpen,
  Bold,
  BriefcaseBusiness,
  Camera,
  Check,
  ClipboardPaste,
  ChevronDown,
  ChevronRight,
  CloudOff,
  Code2,
  Eye,
  EyeOff,
  FileText,
  Folder,
  FolderKanban,
  GraduationCap,
  Heading1,
  Heading2,
  History,
  Home,
  House,
  Heart,
  Image,
  Italic,
  Layers,
  Link,
  Laptop,
  Lightbulb,
  List,
  ListChecks,
  ListTodo,
  LogOut,
  Mail,
  Menu,
  MoreHorizontal,
  Paperclip,
  Pencil,
  Plane,
  Plus,
  Quote,
  Redo2,
  Search,
  Settings,
  Share2,
  Shield,
  Star,
  Table,
  TableColumnsSplit,
  TableRowsSplit,
  Trash2,
  Underline,
  Undo2,
  Upload,
  User,
  Utensils,
  Wifi,
  Wrench,
  X,
} from 'lucide';

const icons = {
  bold: Bold,
  'book-open': BookOpen,
  briefcase: BriefcaseBusiness,
  camera: Camera,
  check: Check,
  'clipboard-paste': ClipboardPaste,
  'chevron-down': ChevronDown,
  'chevron-right': ChevronRight,
  'cloud-off': CloudOff,
  code: Code2,
  eye: Eye,
  'eye-off': EyeOff,
  'file-text': FileText,
  folder: Folder,
  'folder-kanban': FolderKanban,
  'graduation-cap': GraduationCap,
  'heading-1': Heading1,
  'heading-2': Heading2,
  history: History,
  home: Home,
  house: House,
  heart: Heart,
  image: Image,
  italic: Italic,
  layers: Layers,
  link: Link,
  laptop: Laptop,
  lightbulb: Lightbulb,
  list: List,
  'list-checks': ListChecks,
  'list-todo': ListTodo,
  'log-out': LogOut,
  mail: Mail,
  menu: Menu,
  'more-horizontal': MoreHorizontal,
  paperclip: Paperclip,
  pencil: Pencil,
  plane: Plane,
  plus: Plus,
  quote: Quote,
  redo: Redo2,
  search: Search,
  settings: Settings,
  'share-2': Share2,
  shield: Shield,
  star: Star,
  table: Table,
  'table-add-col': BetweenVerticalStart,
  'table-add-row': BetweenHorizontalStart,
  'table-del-col': TableColumnsSplit,
  'table-del-row': TableRowsSplit,
  trash: Trash2,
  underline: Underline,
  undo: Undo2,
  upload: Upload,
  user: User,
  utensils: Utensils,
  wifi: Wifi,
  wrench: Wrench,
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

/**
 * `x-icon="plus:size-8"` überschreibt die sonst feste 1rem-Größe (`size-4`) -
 * für einzelne, bewusst groß gesetzte Symbole wie den Leerzustand.
 */
export function renderIconDirective(el, expression) {
  const [name, className] = expression.replace(/["']/g, '').trim().split(':');
  el.innerHTML = icon(name, className || undefined);
}
