/** Visible strings of the configurator; placeholders use {name}. */
export interface ConfiguratorMessages {
  readonly label: string;
  readonly kind: string;
  readonly kinds: Readonly<Record<string, string>>;
  readonly preset: string;
  readonly presets: Readonly<Record<string, string>>;
  readonly parameters: string;
  readonly fields: Readonly<Record<string, string>>;
  readonly choices: Readonly<Record<string, string>>;
  readonly optional: string;
  readonly seed: string;
  readonly randomSeed: string;
  readonly crop: string;
  readonly cropX: string;
  readonly cropY: string;
  readonly cropWidth: string;
  readonly cropHeight: string;
  readonly resetCrop: string;
  readonly cropArea: string;
  readonly cropFrame: string;
  readonly cropHelp: string;
  readonly noImage: string;
  readonly imageError: string;
  readonly preview: string;
  readonly gridSummary: string;
  readonly stripsSummary: string;
  readonly approximate: string;
  readonly range: string;
  readonly required: string;
  readonly orientationsRequired: string;
  readonly cropOutside: string;
  readonly tooSmall: string;
}

export const configuratorEn: ConfiguratorMessages = {
  label: 'Puzzle configuration',
  kind: 'Game mode',
  kinds: {
    sliding: 'Sliding',
    swap: 'Swap',
    rotation: 'Rotation',
    strips: 'Strips',
    jigsaw: 'Jigsaw',
    nonogram: 'Nonogram',
    memory: 'Memory',
  },
  preset: 'Difficulty',
  presets: { easy: 'Easy', medium: 'Medium', hard: 'Hard', custom: 'Custom' },
  parameters: 'Custom parameters',
  fields: {
    rows: 'Rows',
    cols: 'Columns',
    scrambleMoves: 'Scramble moves',
    orientations: 'Rotations',
    count: 'Strips',
    axis: 'Direction',
    topology: 'Piece layout',
    edge: 'Edge',
    snap: 'Snap distance (px)',
    threshold: 'Darkness threshold (1-255, empty: automatic)',
    invert: 'Fill the light areas',
    requireUnique: 'Only accept a proven unique solution',
    labels: 'Card labels, one per pair and line (the second card of each pair shows the label)',
  },
  choices: {
    horizontal: 'Horizontal',
    vertical: 'Vertical',
    rect: 'Rectangular',
    hex: 'Hexagonal',
    triangle: 'Triangular',
    jigsaw: 'Jigsaw tabs',
    line: 'Straight',
    arc: 'Arc',
    half_circle: 'Half circle',
    sinus: 'Wave',
    no_edge: 'None',
  },
  optional: '(optional)',
  seed: 'Seed',
  randomSeed: 'New seed',
  crop: 'Crop',
  cropX: 'Left (px)',
  cropY: 'Top (px)',
  cropWidth: 'Width (px)',
  cropHeight: 'Height (px)',
  resetCrop: 'Use the whole image',
  cropArea: 'Crop area {width} × {height} px at {x}, {y}',
  cropFrame: 'crop frame',
  cropHelp: 'Drag the frame or its corners. With the keyboard: arrows move the frame, Shift+arrows resize it.',
  noImage: 'Choose an image to preview the crop and the board.',
  imageError: 'The image could not be loaded for the preview.',
  preview: 'Preview',
  gridSummary: '{rows} × {cols} board, tiles of about {width} × {height} px',
  stripsSummary: '{count} {axis} strips of about {size} px',
  approximate: 'Piece outlines are drawn when the puzzle is generated; the preview shows the grid.',
  range: '{field} must be a whole number from {min} to {max}.',
  required: '{field} is required.',
  orientationsRequired: 'Choose at least one rotation.',
  cropOutside: 'The crop must lie inside the {width} × {height} px image.',
  tooSmall: 'The crop is too small for this board.',
};

export const configuratorIt: ConfiguratorMessages = {
  label: 'Configurazione del puzzle',
  kind: 'Modalità di gioco',
  kinds: {
    sliding: 'Scorrevole',
    swap: 'Scambio',
    rotation: 'Rotazione',
    strips: 'Strisce',
    jigsaw: 'Jigsaw',
    nonogram: 'Nonogramma',
    memory: 'Memory',
  },
  preset: 'Difficoltà',
  presets: { easy: 'Facile', medium: 'Media', hard: 'Difficile', custom: 'Personalizzata' },
  parameters: 'Parametri personalizzati',
  fields: {
    rows: 'Righe',
    cols: 'Colonne',
    scrambleMoves: 'Mosse di mescolamento',
    orientations: 'Rotazioni',
    count: 'Strisce',
    axis: 'Direzione',
    topology: 'Forma dei pezzi',
    edge: 'Bordo',
    snap: 'Distanza di aggancio (px)',
    threshold: 'Soglia di scuro (1-255, vuoto: automatica)',
    invert: 'Riempi le zone chiare',
    requireUnique: 'Accetta solo una soluzione unica dimostrata',
    labels: "Etichette delle carte, una per coppia e per riga (la seconda carta di ogni coppia mostra l'etichetta)",
  },
  choices: {
    horizontal: 'Orizzontali',
    vertical: 'Verticali',
    rect: 'Rettangolare',
    hex: 'Esagonale',
    triangle: 'Triangolare',
    jigsaw: 'Incastri',
    line: 'Dritto',
    arc: 'Arco',
    half_circle: 'Semicerchio',
    sinus: 'Onda',
    no_edge: 'Nessuno',
  },
  optional: '(facoltativo)',
  seed: 'Seme',
  randomSeed: 'Nuovo seme',
  crop: 'Ritaglio',
  cropX: 'Sinistra (px)',
  cropY: 'Alto (px)',
  cropWidth: 'Larghezza (px)',
  cropHeight: 'Altezza (px)',
  resetCrop: "Usa tutta l'immagine",
  cropArea: 'Area di ritaglio {width} × {height} px in {x}, {y}',
  cropFrame: 'cornice di ritaglio',
  cropHelp: 'Trascina la cornice o i suoi angoli. Da tastiera: le frecce spostano la cornice, Maiusc+frecce la ridimensionano.',
  noImage: "Scegli un'immagine per vedere l'anteprima del ritaglio e della griglia.",
  imageError: "Impossibile caricare l'immagine per l'anteprima.",
  preview: 'Anteprima',
  gridSummary: 'Griglia {rows} × {cols}, tessere di circa {width} × {height} px',
  stripsSummary: '{count} strisce {axis} di circa {size} px',
  approximate: "I contorni dei pezzi vengono disegnati durante la generazione; l'anteprima mostra la griglia.",
  range: '{field} deve essere un numero intero da {min} a {max}.',
  required: '{field} è obbligatorio.',
  orientationsRequired: 'Scegli almeno una rotazione.',
  cropOutside: "Il ritaglio deve stare dentro l'immagine di {width} × {height} px.",
  tooSmall: 'Il ritaglio è troppo piccolo per questa griglia.',
};

const dictionaries: Readonly<Record<string, ConfiguratorMessages>> = { en: configuratorEn, it: configuratorIt };

export function resolveConfiguratorMessages(
  locale: string | undefined,
  overrides: Partial<ConfiguratorMessages> | undefined,
): ConfiguratorMessages {
  const base = (locale !== undefined ? (dictionaries[locale] ?? dictionaries[locale.split('-')[0] ?? '']) : undefined) ?? configuratorEn;
  return { ...base, ...overrides };
}
