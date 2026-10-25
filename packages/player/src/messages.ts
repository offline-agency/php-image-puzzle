/**
 * Visible strings. Hosts pass `locale` and/or `messages` overrides; the
 * engine itself never produces UI text. Placeholders use {name}.
 */
export interface Messages {
  readonly label: string;
  readonly controls: string;
  readonly loading: string;
  readonly empty: string;
  readonly loadError: string;
  readonly start: string;
  readonly pause: string;
  readonly resume: string;
  readonly undo: string;
  readonly redo: string;
  readonly reset: string;
  readonly shuffle: string;
  readonly hint: string;
  readonly preview: string;
  readonly zoomIn: string;
  readonly zoomOut: string;
  readonly moves: string;
  readonly time: string;
  readonly progress: string;
  readonly hints: string;
  readonly paused: string;
  readonly complete: string;
  readonly illegal: string;
  readonly tile: string;
  readonly tileSelected: string;
  readonly strip: string;
  readonly piece: string;
  readonly piecePlaced: string;
  readonly orientation: string;
  readonly moved: string;
  readonly swapped: string;
  readonly rotated: string;
  readonly placed: string;
  readonly dropped: string;
  readonly hintGiven: string;
  readonly noHint: string;
  readonly instructions: Readonly<Record<string, string>>;
}

export const en: Messages = {
  label: 'Image puzzle',
  controls: 'Puzzle controls',
  loading: 'Loading puzzle…',
  empty: 'No puzzle loaded.',
  loadError: 'The puzzle could not be loaded: {message}',
  start: 'Start',
  pause: 'Pause',
  resume: 'Resume',
  undo: 'Undo',
  redo: 'Redo',
  reset: 'Reset',
  shuffle: 'Shuffle',
  hint: 'Hint',
  preview: 'Show picture',
  zoomIn: 'Zoom in',
  zoomOut: 'Zoom out',
  moves: 'Moves: {n}',
  time: 'Time: {time}',
  progress: '{percent}% correct',
  hints: 'Hints: {n}',
  paused: 'Paused',
  complete: 'Puzzle complete in {moves} moves and {time}.',
  illegal: 'That move is not allowed.',
  tile: 'Tile {n}, row {row}, column {col}',
  tileSelected: 'Tile {n} selected',
  strip: 'Strip {n}, position {position} of {count}',
  piece: 'Piece {n}',
  piecePlaced: 'Piece {n}, placed',
  orientation: 'rotated {degrees} degrees',
  moved: 'Moved tile {n}.',
  swapped: 'Swapped tiles {a} and {b}.',
  rotated: 'Tile {n} now {degrees} degrees.',
  placed: 'Piece {n} placed.',
  dropped: 'Piece {n} dropped.',
  hintGiven: 'Hint: {hint}',
  noHint: 'No hint available.',
  instructions: {
    sliding: 'Use the arrow keys to move between tiles and Enter to slide a tile next to the gap.',
    swap: 'Press Enter on two tiles to swap them. Escape cancels the selection.',
    rotation: 'Press Enter to rotate a tile clockwise, Shift+Enter to rotate it counter-clockwise.',
    strips: 'Press Enter to pick up a strip, the arrow keys to move it, and Enter again to drop it.',
    jigsaw: 'Tab to a piece, move it with the arrow keys (Shift for larger steps) and press Enter to drop it.',
  },
};

export const it: Messages = {
  label: 'Puzzle',
  controls: 'Comandi del puzzle',
  loading: 'Caricamento del puzzle…',
  empty: 'Nessun puzzle caricato.',
  loadError: 'Impossibile caricare il puzzle: {message}',
  start: 'Inizia',
  pause: 'Pausa',
  resume: 'Riprendi',
  undo: 'Annulla',
  redo: 'Ripeti',
  reset: 'Ricomincia',
  shuffle: 'Mescola',
  hint: 'Suggerimento',
  preview: "Mostra l'immagine",
  zoomIn: 'Ingrandisci',
  zoomOut: 'Riduci',
  moves: 'Mosse: {n}',
  time: 'Tempo: {time}',
  progress: '{percent}% corretto',
  hints: 'Suggerimenti: {n}',
  paused: 'In pausa',
  complete: 'Puzzle completato in {moves} mosse e {time}.',
  illegal: 'Mossa non consentita.',
  tile: 'Tessera {n}, riga {row}, colonna {col}',
  tileSelected: 'Tessera {n} selezionata',
  strip: 'Striscia {n}, posizione {position} di {count}',
  piece: 'Pezzo {n}',
  piecePlaced: 'Pezzo {n}, posizionato',
  orientation: 'ruotata di {degrees} gradi',
  moved: 'Tessera {n} spostata.',
  swapped: 'Tessere {a} e {b} scambiate.',
  rotated: 'Tessera {n} ora a {degrees} gradi.',
  placed: 'Pezzo {n} posizionato.',
  dropped: 'Pezzo {n} lasciato.',
  hintGiven: 'Suggerimento: {hint}',
  noHint: 'Nessun suggerimento disponibile.',
  instructions: {
    sliding: 'Usa le frecce per spostarti tra le tessere e Invio per far scorrere una tessera vicina allo spazio vuoto.',
    swap: 'Premi Invio su due tessere per scambiarle. Esc annulla la selezione.',
    rotation: 'Premi Invio per ruotare una tessera in senso orario, Maiusc+Invio in senso antiorario.',
    strips: 'Premi Invio per prendere una striscia, le frecce per spostarla e di nuovo Invio per lasciarla.',
    jigsaw: 'Usa Tab per scegliere un pezzo, le frecce per spostarlo (Maiusc per passi più lunghi) e Invio per lasciarlo.',
  },
};

export const dictionaries: Readonly<Record<string, Messages>> = { en, it };

export function resolveMessages(locale: string | undefined, overrides: Partial<Messages> | undefined): Messages {
  const base = (locale !== undefined ? (dictionaries[locale] ?? dictionaries[locale.split('-')[0] ?? '']) : undefined) ?? en;
  return { ...base, ...overrides };
}

export function format(template: string, values: Readonly<Record<string, string | number>>): string {
  return template.replace(/\{(\w+)\}/g, (match, key: string) => {
    const value = values[key];
    return value === undefined ? match : String(value);
  });
}

export function formatTime(ms: number): string {
  const total = Math.floor(ms / 1000);
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const seconds = total % 60;
  const mm = String(minutes).padStart(hours > 0 ? 2 : 1, '0');
  const ss = String(seconds).padStart(2, '0');
  return hours > 0 ? `${hours}:${mm}:${ss}` : `${mm}:${ss}`;
}
