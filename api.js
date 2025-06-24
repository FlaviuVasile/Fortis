export const BASE_URL = 'http://localhost/Fortis/api/public';


export async function createPlayer(name) {
  const res = await fetch(`${BASE_URL}/players`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name, active: 1 })
  });
  return await res.json();
}

export async function createGame(playerId) {
  const res = await fetch(`${BASE_URL}/games`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ player1_id: playerId })
  });
  return await res.json();
}

export async function getGameById(gameId) {
  const res = await fetch(`${BASE_URL}/games/${gameId}`);
  return await res.json();
}


export async function getGames() {
  const res = await fetch(`${BASE_URL}/games`);
  return await res.json();
}

export async function getPlayers() {
  const res = await fetch(`${BASE_URL}/players`);
  return await res.json();
}


export async function joinGame(playerId, gameId) {
  const res = await fetch(`${BASE_URL}/games/${gameId}/join`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ player_id: playerId })
  });

  
  return await res.json();
}
