const noop = () => {};

export const log = import.meta.env.DEV ? console.warn.bind(console) : noop;
export const warn = import.meta.env.DEV ? console.warn.bind(console) : noop;
export const error = import.meta.env.DEV ? console.error.bind(console) : noop;
