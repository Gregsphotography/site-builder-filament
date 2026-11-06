export class WriteQueue {
  constructor(send, version = 0) {
    this.send = send;
    this.version = version;
    this.sequence = 0;
    this.lastAck = 0;
    this.blocked = false;
    this.epoch = 0;
    this.tail = Promise.resolve();
  }
  enqueue(action, args = []) {
    const epoch = this.epoch;
    const task = this.tail.then(async () => {
      if (epoch !== this.epoch || (this.blocked && action !== 'reloadSaved')) throw new Error('Reload saved content before sending more changes.');
      const requestId = ++this.sequence;
      try {
        const response = await this.send(action, ...args, requestId);
        if (!response || response.requestId !== requestId || requestId <= this.lastAck || !Number.isSafeInteger(response.version) || response.version < this.version) {
          throw new Error('The acknowledgement was missing or out of order. Reload to reconcile the saved page.');
        }
        this.lastAck = requestId;
        this.version = response.version;
        if (action === 'reloadSaved' && response.ok) this.blocked = false;
        if (response.state === 'conflict' || response.state === 'failed') this.blocked = true;
        return response;
      } catch (error) {
        this.blocked = true;
        throw error;
      }
    });
    this.tail = task.catch(() => {});
    return task;
  }
  discardPending() { this.epoch++; }
}

export function stableState(value) {
  // Filament's UUID-keyed repeater maps encode row order before dehydration.
  return JSON.stringify(value);
}
