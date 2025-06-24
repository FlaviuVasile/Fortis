<script>
  import { createPlayer, createGame, getGames, joinGame, getPlayers, getGameById } from './api.js';
  import { createEventDispatcher } from 'svelte';

  let name = '';
  let games = [];
  let players = [];
  let showGames = false;
  let showPlayers = false;
  let error = '';

  const dispatch = createEventDispatcher();

  async function startNewGame() {
    error = '';

    if (!name.trim()) {
      error = 'Introdu un nume de jucător!';
      return;
    }

    try {
      const player = await createPlayer(name);
      if (!player.id) {
        error = 'Eroare la creare jucător.';
        console.log(player);
        return;
      }

      const game = await createGame(player.id);
      if (!game.id) {
        error = 'Eroare la creare joc.';
        console.log(game);
        return;
      }

      const gameFull = await getGameById(game.id);
      dispatch('start-waiting', { player, game: gameFull });
    } catch (e) {
      console.error(e);
      error = 'Eroare server. Verifică backend-ul.';
    }
  }

  async function showGamesList() {
    error = '';
    try {
      const allGames = await getGames();
      games = allGames.filter(game => !game.player2_id);
      showGames = true;
      showPlayers = false;
    } catch (e) {
      console.error(e);
      error = 'Nu pot obține lista de jocuri.';
    }
  }

  async function joinExisting(gameId) {
    error = '';

    if (!name.trim()) {
      error = 'Introdu un nume de jucător!';
      return;
    }

    try {
      const player = await createPlayer(name);
      if (!player.id) {
        error = 'Eroare la creare jucător.';
        return;
      }

      const game = await joinGame(player.id, gameId);
      const gameFull = await getGameById(gameId);
      dispatch('start-game', { player, game: gameFull });
    } catch (e) {
      console.error(e);
      error = 'Eroare la alăturare la joc.';
    }
  }

  async function showPlayersList() {
    error = '';
    try {
      players = await getPlayers();
      showPlayers = true;
      showGames = false;
    } catch (e) {
      console.error(e);
      error = 'Nu pot obține lista de jucători.';
    }
  }
</script>

<h1>Agricola</h1>

<input bind:value={name} placeholder="Nume jucător" />

<br /><br />

<button on:click={startNewGame}>Creează joc nou</button>
<button on:click={showGamesList}>Conectează-te la un joc</button>
<button on:click={showPlayersList}>Vezi jucători activi</button>

{#if error}
  <p style="color: red;">{error}</p>
{/if}

{#if showGames}
  <h3>Jocuri disponibile:</h3>
  {#if games.length === 0}
    <p>Nu există jocuri disponibile momentan.</p>
  {:else}
    <ul>
      {#each games as game}
        <li>
          Joc ID: {game.id}
          <button on:click={() => joinExisting(game.id)}>Alătură-te</button>
        </li>
      {/each}
    </ul>
  {/if}
{/if}

{#if showPlayers}
  <h3>Jucători activi:</h3>
  <ul>
    {#each players as player}
      <li>{player.name}</li>
    {/each}
  </ul>
{/if}
