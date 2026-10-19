const fs = require('node:fs');
const path = require('node:path');
const colours = ['indigo', 'blue', 'green', 'red', 'purple', 'teal', 'orange', 'slate', 'emerald', 'cyan', 'rose', 'amber'];
function sources(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap(entry => {
    if (['vendor', 'node_modules', '.git'].includes(entry.name)) return [];
    const file = path.join(directory, entry.name);
    return entry.isDirectory() ? sources(file) : /\.(html|php|js)$/.test(file) && !file.endsWith('.test.js') ? [file] : [];
  });
}
// menu.js substitutes the selected colour family at runtime. Generate only
// families of classes actually used, keeping the compiled sheet small.
const usedIndigoClasses = new Set(sources('frontend').concat(fs.readdirSync('.').filter(file => file.endsWith('.php')))
  .flatMap(file => fs.readFileSync(file, 'utf8').match(/[a-z:-]+indigo-\d+(?:\/\d+)?/g) || []));
module.exports = {
  content: {
    files: ['./frontend/*.{html,php}', './frontend/js/*.js', './*.php'],
    // Retain PHP forms and JS-created tables, chips and dialog controls.
  },
  safelist: [...usedIndigoClasses].flatMap(value => colours.map(colour => value.replace('indigo', colour))),
  theme: { extend: { fontFamily: { sans: ['sans-serif'] } } },
  plugins: []
};
