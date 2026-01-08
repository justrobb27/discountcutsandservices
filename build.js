const fs = require('fs');
require('dotenv').config();  // Loads .env file

// Read the original HTML
const html = fs.readFileSync('hiring.html', 'utf8');

// Replace placeholder with actual key from .env
const builtHtml = html.replace(/\$\{GOOGLE_API_KEY\}/g, process.env.GOOGLE_API_KEY || 'YOUR_API_KEY');  // Fallback if .env missing

// Create dist folder if it doesn't exist
if (!fs.existsSync('dist')) {
  fs.mkdirSync('dist');
}

// Write the built version
fs.writeFileSync('dist/hiring.html', builtHtml);
console.log('Build complete! Check dist/hiring.html for the injected key.');