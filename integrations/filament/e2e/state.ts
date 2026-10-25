import { fileURLToPath } from 'node:url';

/** Signed-in session of the seeded Acme author, written by auth.setup.ts (git-ignored). */
export const AUTHOR_STATE = fileURLToPath(new URL('../test-results/.auth/author.json', import.meta.url));
