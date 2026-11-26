# Reconnecting to a two-player game

Get the `Turns` Atom for the room and call `$room->start($firstPlayerId, $secondPlayerId)`
once to assign seats. When issuing a socket URL for that room,
include the player's ID from the application session in the signed
`client_id` claim and subscribe the socket to the `game` channel.

On each connection, the Atom uses that ID to find the seat. Its `welcome`
frame contains the seat, the last accepted move, and the current revision.
Send the displayed revision with each move. If a connection drops just after
a move, read the next `welcome` frame to see whether the Atom accepted it.

The [reconnect smoke test](../../tests/Smoke/reconnect-room.test.mjs) runs
two clients across a local Worker restart with a new application bundle.
