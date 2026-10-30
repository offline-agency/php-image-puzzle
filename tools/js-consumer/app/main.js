// Two adapters on one page, each with the same controls, so the browser test
// can drive both through identical steps and compare their game states.
import { mountReact } from './react-app.js';
import { mountVue } from './vue-app.js';

window.puzzles = {};
mountReact(document.getElementById('react-root'));
mountVue(document.getElementById('vue-root'));
