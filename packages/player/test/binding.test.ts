import { bindPlayer } from '../src/index.js';
import type { PlayerBinding, PlayerHandlers } from '../src/index.js';
import { describeAdapterContract } from './adapter-contract.js';
import type { Mounted } from './adapter-contract.js';
import { settle } from './support.js';

describeAdapterContract('bindPlayer', async (container, props, handlers): Promise<Mounted> => {
  let current: PlayerHandlers = handlers;
  let binding: PlayerBinding | null = bindPlayer(container, props, () => current);
  await settle();
  return {
    player() {
      if (binding === null) throw new Error('unmounted');
      return binding.player;
    },
    async update(next, nextHandlers) {
      if (nextHandlers !== undefined) current = nextHandlers;
      binding?.update(next);
      await settle();
    },
    async unmount() {
      binding?.destroy();
      binding = null;
      await settle();
    },
  };
});
