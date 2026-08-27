module.exports = {
  content: [
    './**/*.php',
    './assets/js/**/*.js',
    './js/**/*.js'
  ],
  safelist: [
    // Layout
    /^container$/,
    /^grid$/,
    /^grid-cols-/,
    /^md:grid-cols-/,
    /^col-span-/,
    /^md:col-span-/,
    /^flex$/,
    /^flex-col$/,
    /^items-/,
    /^justify-/,
    /^w-/,
    /^h-/,
    // Spacing
    /^p[trblxy]?-/,
    /^m[trblxy]?-/,
    /^space-[xy]-/,
    // gap-x-*/gap-y-* were absent from the safelist, so they only survived when the
    // content scan happened to catch them. Templates rendered with zero spacing.
    /^gap-/,
    /^gap-[xy]-/,
    /^md:gap-/,
    /^lg:gap-/,
    // Explicit padding classes for icon inputs
    'pl-12',
    'pr-4',
    'pl-10',
    // Typography & colors
    /^text-/,
    /^font-/,
    /^leading-/,
    /^tracking-/,
    /^bg-/,
    /^from-/,
    /^to-/,
    /^via-/,
    // Borders & radius & shadows
    /^border/,
    /^rounded/,
    /^shadow/,
    // Effects & transitions
    /^transition/,
    /^duration-/,
    /^ease-/,
    /^hover:/,
    /^active:/,
    /^focus:/,
    /^focus:ring/,
    // States
    /^disabled:/,
    /^peer-checked:/,
  ],
  theme: {
    extend: {},
  },
  plugins: [],
}
