import React from "react";

type IconProps = React.SVGProps<SVGSVGElement> & {
  size?: number;
};

function IconBase({ size = 18, children, ...props }: IconProps & { children: React.ReactNode }) {
  return (
    <svg
      aria-hidden="true"
      focusable="false"
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      {...props}
    >
      {children}
    </svg>
  );
}

export function ArrowLeftIcon(props: IconProps) {
  return <IconBase {...props}><path d="m15 18-6-6 6-6" /><path d="M9 12h11" /></IconBase>;
}

export function CheckIcon(props: IconProps) {
  return <IconBase {...props}><path d="m5 12 4 4L19 6" /></IconBase>;
}

export function ChevronDownIcon(props: IconProps) {
  return <IconBase {...props}><path d="m6 9 6 6 6-6" /></IconBase>;
}

export function ChevronRightIcon(props: IconProps) {
  return <IconBase {...props}><path d="m9 18 6-6-6-6" /></IconBase>;
}

export function CloseIcon(props: IconProps) {
  return <IconBase {...props}><path d="M18 6 6 18M6 6l12 12" /></IconBase>;
}

export function DelayIcon(props: IconProps) {
  return <IconBase {...props}><circle cx="12" cy="12" r="8.5" /><path d="M12 7v5l3.2 2" /></IconBase>;
}

export function DotsIcon(props: IconProps) {
  return <IconBase {...props}><circle cx="5" cy="12" r=".8" fill="currentColor" stroke="none" /><circle cx="12" cy="12" r=".8" fill="currentColor" stroke="none" /><circle cx="19" cy="12" r=".8" fill="currentColor" stroke="none" /></IconBase>;
}

export function DragIcon(props: IconProps) {
  return <IconBase {...props}><circle cx="8" cy="7" r="1" fill="currentColor" stroke="none" /><circle cx="16" cy="7" r="1" fill="currentColor" stroke="none" /><circle cx="8" cy="12" r="1" fill="currentColor" stroke="none" /><circle cx="16" cy="12" r="1" fill="currentColor" stroke="none" /><circle cx="8" cy="17" r="1" fill="currentColor" stroke="none" /><circle cx="16" cy="17" r="1" fill="currentColor" stroke="none" /></IconBase>;
}

export function FilterIcon(props: IconProps) {
  return <IconBase {...props}><path d="M4 5h16l-6.3 7v5.2l-3.4 1.8v-7z" /></IconBase>;
}

export function HomeIcon(props: IconProps) {
  return <IconBase {...props}><path d="m4 10 8-6 8 6v9H4z" /><path d="M9 19v-6h6v6" /></IconBase>;
}

export function LayersIcon(props: IconProps) {
  return <IconBase {...props}><path d="m12 3 9 5-9 5-9-5z" /><path d="m3 12 9 5 9-5" /><path d="m3 16 9 5 9-5" /></IconBase>;
}

export function PathIcon(props: IconProps) {
  return <IconBase {...props}><path d="M5 4v5c0 2 1 3 3 3h8" /><path d="m13 9 3 3-3 3" /><path d="M5 20v-3c0-2 1-3 3-3" /></IconBase>;
}

export function PlusIcon(props: IconProps) {
  return <IconBase {...props}><path d="M12 5v14M5 12h14" /></IconBase>;
}

export function RedoIcon(props: IconProps) {
  return <IconBase {...props}><path d="m15 7 4 4-4 4" /><path d="M19 11h-8a6 6 0 0 0-6 6" /></IconBase>;
}

export function SearchIcon(props: IconProps) {
  return <IconBase {...props}><circle cx="11" cy="11" r="7" /><path d="m20 20-4-4" /></IconBase>;
}

export function SparkIcon(props: IconProps) {
  return <IconBase {...props}><path d="m12 3 1.2 3.8L17 8l-3.8 1.2L12 13l-1.2-3.8L7 8l3.8-1.2z" /><path d="m18 14 .8 2.2L21 17l-2.2.8L18 20l-.8-2.2L15 17l2.2-.8z" /></IconBase>;
}

export function TrashIcon(props: IconProps) {
  return <IconBase {...props}><path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13" /></IconBase>;
}

export function TriggerIcon(props: IconProps) {
  return <IconBase {...props}><path d="M13 2 5.5 13H11l-1 9 8.5-12H13z" /></IconBase>;
}

export function UndoIcon(props: IconProps) {
  return <IconBase {...props}><path d="m9 7-4 4 4 4" /><path d="M5 11h8a6 6 0 0 1 6 6" /></IconBase>;
}

export function UtilityIcon(props: IconProps) {
  return <IconBase {...props}><path d="M12 3v4M12 17v4M3 12h4M17 12h4" /><circle cx="12" cy="12" r="4" /></IconBase>;
}

export function WorkflowIcon(props: IconProps) {
  return <IconBase {...props}><rect x="3" y="3" width="7" height="6" rx="1.5" /><rect x="14" y="15" width="7" height="6" rx="1.5" /><path d="M6.5 9v3a3 3 0 0 0 3 3H14" /></IconBase>;
}

export function ZoomInIcon(props: IconProps) {
  return <IconBase {...props}><circle cx="10.5" cy="10.5" r="6.5" /><path d="M10.5 7.5v6M7.5 10.5h6M16 16l4 4" /></IconBase>;
}

export function ZoomOutIcon(props: IconProps) {
  return <IconBase {...props}><circle cx="10.5" cy="10.5" r="6.5" /><path d="M7.5 10.5h6M16 16l4 4" /></IconBase>;
}
