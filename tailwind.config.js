/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './assets/css/tailwind.css', // Your Tailwind CSS file
    './assets/css/frontend.css', // Add any other custom CSS files that might have Tailwind classes
    './includes/**/*.php', // PHP files in the includes directory
    './**/*.php', // All PHP files within the project if needed
  ],
  theme: {
    extend: {},
  },
  plugins: [],
}
