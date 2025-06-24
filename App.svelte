<script>
  import StartScreen from './StartScreen.svelte';
  import WaitScreen from './WaitScreen.svelte'; // 🆕 Ecran de așteptare
  import GameBoard from './GameBoard.svelte';
  import { getGames } from './api.js';

  let player = null;
  let game = null;
  let waiting = false;
  let games = [];

  // Primul jucător -> așteaptă
  function handleStartWaiting(event) {
    player = event.detail.player;
    game = event.detail.game;
    waiting = true;
  }

  // Al doilea jucător -> merge direct în tabla de joc
  function handleStartGame(event) {
    player = event.detail.player;
    game = event.detail.game;
    waiting = false;
  }
</script>

{#if !player}
  <StartScreen on:start-waiting={handleStartWaiting} on:start-game={handleStartGame} />
{:else if waiting}
  <WaitScreen 
  {game} 
  on:active={(event) => {
    game = event.detail.game;  // 🔑 actualizează game
    waiting = false;           // 🔑 oprește waiting => apare GameBoard
  }} 
/>
{:else}
  <GameBoard 
  player={player}
  player1={{ name: game.player1_name }}
  player2={{ name: game.player2_name }}
/>

{/if}
