import type { LucideIcon } from 'lucide-react';
import {
  Activity, ArrowLeft, ArrowRightLeft, BadgeCheck, Ban, Banknote, Bell, BookOpen, Briefcase, Building2, CalendarClock, CalendarDays,
  ChartColumn, ChevronRight, CircleCheck, CircleDollarSign, CircleX, ClipboardCheck, ClipboardList, Copy, CreditCard, Crown, Download,
  ExternalLink, Eye, EyeOff, FileCheck2, FileSpreadsheet, FileText, Flag, FolderOpen, HandCoins, Handshake, Headset, History, House, IdCard,
  ImagePlus, Inbox, Info, KeyRound, Landmark, LayoutDashboard, Link2, ListChecks, Lock, LogOut, Luggage, Mail, MapPin, Megaphone,
  MessageCircle, Package, PackageCheck, PackageX, Pencil, Percent, Phone, Plus, QrCode, Receipt, ReceiptText, RefreshCw, ScrollText,
  Send, Settings, ShieldAlert, ShieldCheck, Sparkles, Star, StickyNote, Store, Ticket, Timer, Trash2, TrendingUp, TriangleAlert, Undo2,
  MonitorSmartphone, PiggyBank, Route, Scale, Upload, UserCheck, UserCog, UserPlus, UserRound, UserX, Users, Wallet,
} from 'lucide-react';

/** Ikon garis sederhana (24×24, stroke currentColor), dibuat khusus untuk UMRAH CONNECT. */
type P = { className?: string };
const base = 'shrink-0';

function Svg({ className = 'h-5 w-5', children }: P & { children: React.ReactNode }) {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round" className={`${base} ${className}`}>
      {children}
    </svg>
  );
}

export const IconSearch = (p: P) => <Svg {...p}><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></Svg>;
export const IconCalendar = (p: P) => <Svg {...p}><rect x="3" y="5" width="18" height="16" rx="2" /><path d="M3 10h18M8 3v4M16 3v4" /></Svg>;
export const IconPin = (p: P) => <Svg {...p}><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21Z" /><circle cx="12" cy="9.5" r="2.5" /></Svg>;
export const IconWallet = (p: P) => <Svg {...p}><path d="M4 7h15a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a2 2 0 0 1 2-2h11" /><path d="M16 13.5h.01" /></Svg>;
export const IconPlane = (p: P) => <Svg {...p}><path d="M10.5 13.5 3 11l1.5-1.5 8 1 4-4.5c1-1 2.5-1.3 3-.8s.2 2-.8 3l-4.5 4 1 8L14.5 22 12 14.5" /><path d="m6 17-2 1 1-2" /></Svg>;
export const IconShield = (p: P) => <Svg {...p}><path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.3 7.5 9.5 4.3-1.2 7.5-4.9 7.5-9.5V6Z" /><path d="m8.8 12 2.2 2.2 4.2-4.4" /></Svg>;
export const IconTag = (p: P) => <Svg {...p}><path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9Z" /><circle cx="7.5" cy="7.5" r="1.5" /></Svg>;
export const IconBank = (p: P) => <Svg {...p}><path d="M3 10 12 4l9 6M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18" /></Svg>;
export const IconUsers = (p: P) => <Svg {...p}><circle cx="9" cy="8" r="3.5" /><path d="M2.5 20a6.5 6.5 0 0 1 13 0" /><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14a6.5 6.5 0 0 1 3.5 6" /></Svg>;
export const IconHotel = (p: P) => <Svg {...p}><path d="M4 21V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v16M2 21h20" /><path d="M9 7h1M14 7h1M9 11h1M14 11h1M10 21v-4h4v4" /></Svg>;
export const IconClock = (p: P) => <Svg {...p}><circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" /></Svg>;
export const IconFilter = (p: P) => <Svg {...p}><path d="M4 6h16M7 12h10M10 18h4" /></Svg>;
export const IconX = (p: P) => <Svg {...p}><path d="M6 6l12 12M18 6 6 18" /></Svg>;
export const IconMenu = (p: P) => <Svg {...p}><path d="M4 7h16M4 12h16M4 17h16" /></Svg>;
export const IconArrowRight = (p: P) => <Svg {...p}><path d="M5 12h14M13 6l6 6-6 6" /></Svg>;
export const IconCheck = (p: P) => <Svg {...p}><path d="m5 12.5 4.5 4.5L19 7.5" /></Svg>;
export const IconBriefcase = (p: P) => <Svg {...p}><rect x="3" y="7" width="18" height="13" rx="2" /><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18" /></Svg>;
export const IconBuilding = (p: P) => <Svg {...p}><path d="M4 21V4a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v17M15 9h4a1 1 0 0 1 1 1v11M2 21h20" /><path d="M8 7h3M8 11h3M8 15h3" /></Svg>;

/*
 * Ikon tambahan dari lucide-react, disamakan gayanya dengan ikon di atas
 * (garis 1.8, ujung bulat, ukuran bawaan h-5 w-5, aria-hidden). Pakai dari file ini saja agar seragam.
 */

function wrap(I: LucideIcon) {
  const Wrapped = ({ className = 'h-5 w-5' }: P) => <I aria-hidden="true" strokeWidth={1.8} className={`${base} ${className}`} />;
  Wrapped.displayName = `Icon(${I.displayName ?? 'lucide'})`;
  return Wrapped;
}

export const IconActivity = wrap(Activity);
export const IconBack = wrap(ArrowLeft);
export const IconTransfer = wrap(ArrowRightLeft);
export const IconVerified = wrap(BadgeCheck);
export const IconBan = wrap(Ban);
export const IconCash = wrap(Banknote);
export const IconBell = wrap(Bell);
export const IconBook = wrap(BookOpen);
export const IconWork = wrap(Briefcase);
export const IconOffice = wrap(Building2);
export const IconCalendarClock = wrap(CalendarClock);
export const IconCalendarDays = wrap(CalendarDays);
export const IconChart = wrap(ChartColumn);
export const IconChevronRight = wrap(ChevronRight);
export const IconCircleCheck = wrap(CircleCheck);
export const IconCommission = wrap(CircleDollarSign);
export const IconCircleX = wrap(CircleX);
export const IconReview = wrap(ClipboardCheck);
export const IconClipboard = wrap(ClipboardList);
export const IconCopy = wrap(Copy);
export const IconCard = wrap(CreditCard);
export const IconCrown = wrap(Crown);
export const IconDownload = wrap(Download);
export const IconExternal = wrap(ExternalLink);
export const IconEye = wrap(Eye);
export const IconEyeOff = wrap(EyeOff);
export const IconFileCheck = wrap(FileCheck2);
export const IconSpreadsheet = wrap(FileSpreadsheet);
export const IconFile = wrap(FileText);
export const IconFlag = wrap(Flag);
export const IconFolder = wrap(FolderOpen);
export const IconPayout = wrap(HandCoins);
export const IconHandshake = wrap(Handshake);
export const IconSupport = wrap(Headset);
export const IconHistory = wrap(History);
export const IconHome = wrap(House);
export const IconIdCard = wrap(IdCard);
export const IconImagePlus = wrap(ImagePlus);
export const IconInbox = wrap(Inbox);
export const IconInfo = wrap(Info);
export const IconKey = wrap(KeyRound);
export const IconLandmark = wrap(Landmark);
export const IconDashboard = wrap(LayoutDashboard);
export const IconLink = wrap(Link2);
export const IconChecklist = wrap(ListChecks);
export const IconLock = wrap(Lock);
export const IconLogout = wrap(LogOut);
export const IconLuggage = wrap(Luggage);
export const IconMail = wrap(Mail);
export const IconMapPin = wrap(MapPin);
export const IconMegaphone = wrap(Megaphone);
export const IconChat = wrap(MessageCircle);
export const IconPackage = wrap(Package);
export const IconPackageCheck = wrap(PackageCheck);
export const IconPackageX = wrap(PackageX);
export const IconEdit = wrap(Pencil);
export const IconPercent = wrap(Percent);
export const IconPhone = wrap(Phone);
export const IconPlus = wrap(Plus);
export const IconQr = wrap(QrCode);
export const IconReceipt = wrap(Receipt);
export const IconInvoice = wrap(ReceiptText);
export const IconRefresh = wrap(RefreshCw);
export const IconLog = wrap(ScrollText);
export const IconSend = wrap(Send);
export const IconSettings = wrap(Settings);
export const IconShieldAlert = wrap(ShieldAlert);
export const IconShieldCheck = wrap(ShieldCheck);
export const IconSparkles = wrap(Sparkles);
export const IconStar = wrap(Star);
export const IconNote = wrap(StickyNote);
export const IconStore = wrap(Store);
export const IconTicket = wrap(Ticket);
export const IconTimer = wrap(Timer);
export const IconTrash = wrap(Trash2);
export const IconTrend = wrap(TrendingUp);
export const IconWarning = wrap(TriangleAlert);
export const IconUndo = wrap(Undo2);
export const IconUpload = wrap(Upload);
export const IconUserCheck = wrap(UserCheck);
export const IconUserCog = wrap(UserCog);
export const IconUserPlus = wrap(UserPlus);
export const IconUser = wrap(UserRound);
export const IconUserX = wrap(UserX);
export const IconTeam = wrap(Users);
export const IconMoney = wrap(Wallet);
export const IconPiggy = wrap(PiggyBank);
export const IconDevices = wrap(MonitorSmartphone);
export const IconRoute = wrap(Route);
export const IconScale = wrap(Scale);
