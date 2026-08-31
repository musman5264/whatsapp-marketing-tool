import{j as a}from"./app-c05YpUGE.js";import{u as c}from"./useTranslation-BDX0bclE.js";import{c as d}from"./createLucideIcon-BH9XfobX.js";import{F as h}from"./flame-CmoxdfVk.js";/**
 * @license lucide-react v0.575.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const i=[["path",{d:"m10 20-1.25-2.5L6 18",key:"18frcb"}],["path",{d:"M10 4 8.75 6.5 6 6",key:"7mghy3"}],["path",{d:"m14 20 1.25-2.5L18 18",key:"1chtki"}],["path",{d:"m14 4 1.25 2.5L18 6",key:"1b4wsy"}],["path",{d:"m17 21-3-6h-4",key:"15hhxa"}],["path",{d:"m17 3-3 6 1.5 3",key:"11697g"}],["path",{d:"M2 12h6.5L10 9",key:"kv9z4n"}],["path",{d:"m20 10-1.5 2 1.5 2",key:"1swlpi"}],["path",{d:"M22 12h-6.5L14 15",key:"1mxi28"}],["path",{d:"m4 10 1.5 2L4 14",key:"k9enpj"}],["path",{d:"m7 21 3-6-1.5-3",key:"j8hb9u"}],["path",{d:"m7 3 3 6h4",key:"1otusx"}]],b=d("snowflake",i);/**
 * @license lucide-react v0.575.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const k=[["path",{d:"M14 4v10.54a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z",key:"17jzev"}]],p=d("thermometer",k),o={hot:{variant:"danger",Icon:h,labelKey:"leads.band_hot"},warm:{variant:"warning",Icon:p,labelKey:"leads.band_warm"},cold:{variant:"default",Icon:b,labelKey:"leads.band_cold"}},u={danger:"bg-coral-50 text-coral-800 border-coral-200 dark:bg-coral-950/40 dark:text-coral-300 dark:border-coral-800",warning:"bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-700",default:"bg-neutral-100 text-neutral-600 border-neutral-200 dark:bg-neutral-800 dark:text-neutral-300 dark:border-neutral-700"};function j({band:r,score:e,showScore:l=!0}){const{t}=c();if(!r)return null;const{variant:s,Icon:m,labelKey:n}=o[r]??o.cold;return a.jsxs("span",{className:`inline-flex items-center gap-1 rounded-soft border px-1.5 py-0.5 text-xs font-medium ${u[s]}`,title:t(n),children:[a.jsx(m,{className:"h-3 w-3","aria-hidden":"true"}),a.jsx("span",{className:"sr-only",children:t(n)}),l&&e!==null&&e!==void 0&&a.jsx("span",{children:e})]})}export{j as default};
