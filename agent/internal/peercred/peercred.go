package peercred

// noUser is returned alongside errors. It is the traditional "nobody" ID
// (2^32-1 is reserved and never assigned), so a caller that forgets to check
// the error still cannot be mistaken for root (UID 0).
const noUser = ^uint32(0)
